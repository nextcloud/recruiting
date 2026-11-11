<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Document;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\SeenMailMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Imap\ImapClient;
use OCA\Recruiting\Imap\ImapException;
use OCA\Recruiting\Service\AiService;
use OCA\Recruiting\Service\AutoAckGuard;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\IngestionService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Files\GenericFileException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\Files\StorageTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * One intake run: the time budget, transient vs. permanent failures and the
 * quarantine, automatic mail, forged senders and the ingestion status.
 */
class IngestionRunTest extends TestCase {
	private ConfigService&MockObject $config;
	private OpeningMapper&MockObject $openingMapper;
	private CandidateMapper&MockObject $candidateMapper;
	private SeenMailMapper&MockObject $seenMail;
	private TeamMemberMapper&MockObject $teamMapper;
	private CandidateService&MockObject $candidates;
	private DocumentService&MockObject $documents;
	private MailService&MockObject $mail;
	private TimelineService&MockObject $timeline;
	private NotificationService&MockObject $notifications;
	private AutoAckGuard&MockObject $guard;
	private ImapClient&MockObject $client;
	private IngestionService $service;
	/** @var list<int> */
	private array $clock = [];
	private ?array $storedStatus = null;
	/** @var array<int, string> uid => raw message */
	private array $mailbox = [];
	/** @var array<string, array{done: bool, attempts: int, candidateId: ?int, error: ?string, quarantined: bool}> a fake ledger, by key */
	private array $ledger = [];
	private AiService&MockObject $ai;

	protected function setUp(): void {
		$this->config = $this->createMock(ConfigService::class);
		$this->config->method('isImapEnabled')->willReturn(true);
		$this->config->method('getImapConfig')->willReturn([
			'host' => 'imap.example.com',
			'port' => 993,
			'security' => 'ssl',
			'user' => 'jobs@company.com',
			'password' => 'secret',
		]);
		$this->config->method('getIngestionStatus')->willReturnCallback(fn () => $this->storedStatus);
		$this->config->method('setIngestionStatus')->willReturnCallback(function (array $status): void {
			$this->storedStatus = $status;
		});

		$this->openingMapper = $this->createMock(OpeningMapper::class);
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->seenMail = $this->createMock(SeenMailMapper::class);
		$this->fakeLedger();
		$this->teamMapper = $this->createMock(TeamMemberMapper::class);
		$this->candidates = $this->createMock(CandidateService::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->mail = $this->createMock(MailService::class);
		$this->mail->method('preview')->willReturn(['subject' => 'Received', 'body' => 'Thanks', 'to' => '']);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->guard = $this->createMock(AutoAckGuard::class);
		$this->guard->method('tryAcquire')->willReturn(true);
		$this->ai = $this->createMock(AiService::class);

		$this->client = $this->createMock(ImapClient::class);
		$this->client->method('searchUnseen')->willReturnCallback(fn () => array_keys($this->mailbox));
		$this->client->method('fetchMessage')->willReturnCallback(fn (int $uid) => $this->mailbox[$uid]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->clock !== [] ? array_shift($this->clock) : 0);
		$time->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-11-10T12:00:00+00:00'));

		$this->service = $this->serviceWith($this->guard, $time);
	}

	private function serviceWith(AutoAckGuard $guard, ITimeFactory $time): IngestionService {
		return new class($this->client, $this->config, $this->openingMapper, $this->candidateMapper, $this->seenMail, $this->teamMapper, $this->candidates, $this->documents, $this->mail, $this->timeline, $this->notifications, $this->ai, $time, new NullLogger(), $guard) extends IngestionService {
			public function __construct(
				private ImapClient $testClient,
				...$args,
			) {
				parent::__construct(...$args);
			}

			#[\Override]
			protected function createClient(string $host, int $port, string $security): ImapClient {
				return $this->testClient;
			}
		};
	}

	/**
	 * The ledger as SeenMailMapper keeps it: attempts are counted when an
	 * attempt begins and start over on success or quarantine.
	 */
	private function fakeLedger(): void {
		$row = fn (string $key): array => $this->ledger[$key] ?? ['done' => false, 'attempts' => 0, 'candidateId' => null, 'error' => null, 'quarantined' => false];
		$this->seenMail->method('beginAttempt')->willReturnCallback(function (string $key) use ($row): array {
			$entry = $row($key);
			if (!$entry['done']) {
				$entry['attempts']++;
			}
			$this->ledger[$key] = $entry;
			return ['done' => $entry['done'], 'attempts' => $entry['attempts'], 'candidateId' => $entry['candidateId']];
		});
		$this->seenMail->method('attachCandidate')->willReturnCallback(function (string $key, int $candidateId) use ($row): void {
			$this->ledger[$key] = array_merge($row($key), ['candidateId' => $candidateId]);
		});
		$this->seenMail->method('remember')->willReturnCallback(function (string $key) use ($row): void {
			$this->ledger[$key] = array_merge($row($key), ['done' => true, 'attempts' => 0, 'error' => null]);
		});
		$this->seenMail->method('recordError')->willReturnCallback(function (string $key, string $error) use ($row): void {
			$this->ledger[$key] = array_merge($row($key), ['error' => $error]);
		});
		$this->seenMail->method('markQuarantined')->willReturnCallback(function (string $key, ?string $error = null) use ($row): void {
			$this->ledger[$key] = array_merge($row($key), ['attempts' => 0, 'quarantined' => true, 'error' => $error]);
		});
	}

	private function priorAttempts(string $key, int $attempts, ?int $candidateId = null): void {
		$this->ledger[$key] = ['done' => false, 'attempts' => $attempts, 'candidateId' => $candidateId, 'error' => 'earlier', 'quarantined' => false];
	}

	private static function raw(string $from = 'Jane Doe <jane@example.com>', string $extraHeaders = '', string $to = 'jobs+backend-dev@company.com', string $messageId = '<m1@example.com>'): string {
		return 'From: ' . $from . "\r\n"
			. 'To: ' . $to . "\r\n"
			. ($messageId !== '' ? 'Message-ID: ' . $messageId . "\r\n" : '')
			. "Subject: Application\r\n"
			. $extraHeaders
			. "Content-Type: text/plain; charset=utf-8\r\n"
			. "\r\n"
			. "Hello.\r\n";
	}

	private function openOpening(bool $autoAck = true): Opening {
		$opening = new Opening();
		$opening->setId(7);
		$opening->setTitle('Backend Engineer');
		$opening->setStatus(Opening::STATUS_OPEN);
		$opening->setAutoAck($autoAck);
		$this->openingMapper->method('findByMailSlug')->willReturn($opening);
		return $opening;
	}

	private function candidate(int $id = 55, string $email = 'jane@example.com'): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setDisplayName('Jane Doe');
		$candidate->setEmail($email);
		$candidate->setOpeningId(7);
		return $candidate;
	}

	public function testANewApplicationIsCreatedAcknowledgedAndCounted(): void {
		$this->mailbox = [1 => self::raw()];
		$this->openOpening();
		$this->candidates->expects($this->once())->method('insertCandidate')->willReturn($this->candidate());
		$this->mail->expects($this->once())->method('sendToCandidate');
		$this->client->expects($this->once())->method('markSeen')->with(1);

		$result = $this->service->fetchNow();

		$this->assertSame(1, $result['fetched']);
		$this->assertSame(1, $result['created']);
		$this->assertSame(0, $result['skipped']);
		$this->assertNull($result['error']);
		$this->assertSame($result['fetched'], $this->storedStatus['fetched']);
		$this->assertSame('Recruiting-Failed', $this->storedStatus['quarantineFolder']);
	}

	public function testTheRunStopsWhenTheTimeBudgetIsUsedUp(): void {
		$this->mailbox = [1 => self::raw(messageId: '<a@x>'), 2 => self::raw(messageId: '<b@x>'), 3 => self::raw(messageId: '<c@x>')];
		$this->candidates->method('insertCandidate')->willReturn($this->candidate());
		// start, before uid 1, before uid 2, before uid 3
		$this->clock = [1000, 1000, 1030, 1060];
		$this->client->expects($this->exactly(2))->method('markSeen');

		$result = $this->service->fetchNow();

		$this->assertSame(2, $result['fetched']);
	}

	public function testAtMost500MailsPerRun(): void {
		$this->mailbox = [];
		foreach (range(1, 501) as $uid) {
			$this->mailbox[$uid] = self::raw(messageId: '<m' . $uid . '@example.com>');
		}
		$this->candidates->method('insertCandidate')->willReturn($this->candidate());
		$this->client->expects($this->exactly(500))->method('fetchMessage');

		$this->assertSame(500, $this->service->fetchNow()['fetched']);
	}

	public function testIdenticalMailsWithoutMessageIdAreIngestedOnce(): void {
		$this->mailbox = [1 => self::raw(messageId: ''), 2 => self::raw(messageId: '')];
		$this->candidates->expects($this->once())->method('insertCandidate')->willReturn($this->candidate());
		$this->client->expects($this->exactly(2))->method('markSeen');

		$this->assertSame(1, $this->service->fetchNow()['created']);
	}

	public static function transientFailures(): array {
		return [
			'database' => [new DbException('connection lost')],
			// HintException constructors need a booted server
			'storage unavailable' => [(new \ReflectionClass(StorageNotAvailableException::class))->newInstanceWithoutConstructor()],
			'storage timeout (subclass)' => [(new \ReflectionClass(StorageTimeoutException::class))->newInstanceWithoutConstructor()],
			'not permitted' => [new NotPermittedException('read-only')],
			'generic file' => [new GenericFileException('disk full')],
			'wrapped database error' => [new \RuntimeException('insert failed', 0, new DbException('deadlock'))],
		];
	}

	#[DataProvider('transientFailures')]
	public function testATransientFailureLeavesTheMailUnseenAndStopsTheRun(\Exception $failure): void {
		$this->mailbox = [1 => self::raw(messageId: '<a@x>'), 2 => self::raw(messageId: '<b@x>')];
		$this->candidates->method('insertCandidate')->willThrowException($failure);
		$this->client->expects($this->never())->method('markSeen');
		$this->client->expects($this->once())->method('fetchMessage');
		$this->client->expects($this->never())->method('moveMessage');

		$result = $this->service->fetchNow();

		$this->assertSame($failure->getMessage(), $result['error']);
		$this->assertSame($failure->getMessage(), $this->storedStatus['error']);
		$this->assertSame(1, $result['fetched']);
		// The attempt counts, so a mail that always fails cannot block the mailbox forever
		$this->assertSame(1, $this->ledger['<a@x>']['attempts']);
		$this->assertSame($failure->getMessage(), $this->ledger['<a@x>']['error']);
	}

	public function testTheTwelfthTransientFailureQuarantinesTheMailAndTheRunGoesOn(): void {
		$this->mailbox = [1 => self::raw(messageId: '<stuck@x>'), 2 => self::raw(messageId: '<ok@x>')];
		$this->priorAttempts('<stuck@x>', 11);
		$calls = 0;
		$this->candidates->method('insertCandidate')->willReturnCallback(function () use (&$calls) {
			if ($calls++ === 0) {
				throw new DbException('deadlock');
			}
			return $this->candidate(56);
		});
		$this->client->method('ensureMailbox')->willReturn('Recruiting-Failed');
		$this->client->expects($this->once())->method('moveMessage')->with(1, 'Recruiting-Failed');

		$result = $this->service->fetchNow();

		$this->assertTrue($this->ledger['<stuck@x>']['quarantined']);
		$this->assertSame(1, $result['created']);
		$this->assertNull($result['error']);
	}

	public function testAMailWhoseAttemptsNeverFinishedIsQuarantinedWithoutProcessing(): void {
		// Twelve attempts began and none recorded a result: crashes or timeouts
		$this->mailbox = [1 => self::raw(messageId: '<crash@x>')];
		$this->priorAttempts('<crash@x>', 12);
		$this->candidates->expects($this->never())->method('insertCandidate');
		$this->client->expects($this->once())->method('markSeen')->with(1);
		$this->client->method('ensureMailbox')->willReturn('Recruiting-Failed');
		$this->client->expects($this->once())->method('moveMessage');

		$this->service->fetchNow();

		$this->assertTrue($this->ledger['<crash@x>']['quarantined']);
		$this->assertSame(0, $this->ledger['<crash@x>']['attempts']);
	}

	public function testTheAttemptIsCountedBeforeTheCandidateIsCreated(): void {
		$this->mailbox = [1 => self::raw()];
		$order = [];
		$this->candidates->method('insertCandidate')->willReturnCallback(function () use (&$order) {
			$order[] = 'insert:' . $this->ledger['<m1@example.com>']['attempts'];
			return $this->candidate();
		});

		$this->service->fetchNow();

		$this->assertSame(['insert:1'], $order);
		// Success starts the count over
		$this->assertSame(0, $this->ledger['<m1@example.com>']['attempts']);
		$this->assertTrue($this->ledger['<m1@example.com>']['done']);
		$this->assertSame(55, $this->ledger['<m1@example.com>']['candidateId']);
	}

	public function testALedgerFailureStopsTheRunWithoutProcessing(): void {
		$this->mailbox = [1 => self::raw()];
		$seenMail = $this->createMock(SeenMailMapper::class);
		$seenMail->method('beginAttempt')->willThrowException(new DbException('database gone'));
		$this->seenMail = $seenMail;
		$this->candidates->expects($this->never())->method('insertCandidate');
		$this->client->expects($this->never())->method('markSeen');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-10T12:00:00+00:00'));

		$result = $this->serviceWith($this->guard, $time)->fetchNow();

		$this->assertSame('database gone', $result['error']);
	}

	public function testTransientClassificationRecognisesDoctrineByName(): void {
		$this->assertTrue(IngestionService::isTransient(new DbException('x')));
		$this->assertFalse(IngestionService::isTransient(new \RuntimeException('x')));
		$this->assertFalse(IngestionService::isTransient(new \OCA\Recruiting\Exception\NotPermittedException('app permission, not storage')));
	}

	public function testAPermanentFailureIsRetriedUnseenBeforeTheThirdAttempt(): void {
		$this->mailbox = [1 => self::raw(messageId: '<poison@x>'), 2 => self::raw(messageId: '<ok@x>')];
		$this->priorAttempts('<poison@x>', 1);
		$calls = 0;
		$this->candidates->method('insertCandidate')->willReturnCallback(function () use (&$calls) {
			if ($calls++ === 0) {
				throw new \RuntimeException('broken mail');
			}
			return $this->candidate();
		});
		$this->client->expects($this->once())->method('markSeen')->with(2);
		$this->client->expects($this->never())->method('moveMessage');

		$result = $this->service->fetchNow();

		$this->assertSame(2, $this->ledger['<poison@x>']['attempts']);
		$this->assertSame('broken mail', $this->ledger['<poison@x>']['error']);

		$this->assertSame(2, $result['fetched']);
		$this->assertSame(1, $result['created']);
		$this->assertNull($result['error']);
	}

	public function testTheThirdFailureQuarantinesTheMail(): void {
		$this->mailbox = [9 => self::raw(messageId: '<poison@x>')];
		$this->priorAttempts('<poison@x>', 2);
		$this->candidates->method('insertCandidate')->willThrowException(new \RuntimeException('broken mail'));
		$this->seenMail->expects($this->once())->method('markQuarantined')->with('<poison@x>', 'broken mail');
		$this->client->expects($this->once())->method('markSeen')->with(9);
		$this->client->expects($this->once())->method('ensureMailbox')->with('Recruiting-Failed')->willReturn('INBOX.Recruiting-Failed');
		$this->client->expects($this->once())->method('moveMessage')->with(9, 'INBOX.Recruiting-Failed');
		$this->client->method('messageCount')->with('INBOX.Recruiting-Failed')->willReturn(4);

		$result = $this->service->fetchNow();

		$this->assertSame(4, $result['quarantined']);
		$this->assertSame('INBOX.Recruiting-Failed', $result['quarantineFolder']);
		$this->assertSame(4, $this->storedStatus['quarantined']);
		$this->assertSame('INBOX.Recruiting-Failed', $this->storedStatus['quarantineFolder']);
	}

	public function testAFailedMoveStillMarksTheMailSeen(): void {
		$this->mailbox = [9 => self::raw(messageId: '<poison@x>')];
		$this->priorAttempts('<poison@x>', 2);
		$this->candidates->method('insertCandidate')->willThrowException(new \RuntimeException('broken mail'));
		$this->client->expects($this->once())->method('markSeen')->with(9);
		$this->client->method('ensureMailbox')->willThrowException(new ImapException('NO permission denied'));

		$result = $this->service->fetchNow();

		$this->assertNull($result['error']);
	}

	public function testMailsWithoutMessageIdAreKeyedByContentHash(): void {
		$raw = self::raw(messageId: '');
		$this->mailbox = [1 => $raw];
		$this->candidates->method('insertCandidate')->willThrowException(new \RuntimeException('broken'));

		$this->service->fetchNow();

		$this->assertSame(1, $this->ledger['sha256:' . hash('sha256', $raw)]['attempts']);
	}

	public function testTheQuarantineCountComesFromTheFolderAndRemembersItsName(): void {
		$this->storedStatus = ['quarantined' => 2, 'quarantineFolder' => 'INBOX.Recruiting-Failed'];
		$this->mailbox = [];
		$this->client->method('messageCount')->with('INBOX.Recruiting-Failed')->willReturn(0);

		$result = $this->service->fetchNow();

		$this->assertSame(0, $result['quarantined']);
		$this->assertSame('INBOX.Recruiting-Failed', $result['quarantineFolder']);
	}

	public function testAMissingQuarantineFolderCountsAsEmpty(): void {
		$this->mailbox = [];
		$this->client->method('messageCount')->willThrowException(new ImapException('NO no such mailbox'));

		$this->assertSame(0, $this->service->fetchNow()['quarantined']);
	}

	public function testAnUnreachableMailboxIsRecordedAndRethrown(): void {
		$this->client->method('login')->willThrowException(new ImapException('auth failed'));

		try {
			$this->service->fetchNow();
			$this->fail('expected ImapException');
		} catch (ImapException) {
		}
		$this->assertSame('auth failed', $this->storedStatus['error']);
	}

	public static function automaticMails(): array {
		return [
			'bounce' => [self::raw('Mail Delivery System <MAILER-DAEMON@mx.example.com>', "Content-Type: multipart/report; boundary=x\r\n")],
			'out of office' => [self::raw(extraHeaders: "Auto-Submitted: auto-replied\r\n")],
			'mailing list' => [self::raw(extraHeaders: "List-Id: <jobs.lists.example.org>\r\n")],
		];
	}

	#[DataProvider('automaticMails')]
	public function testAutomaticMailIsSkippedSeenAndCounted(string $raw): void {
		$this->mailbox = [1 => $raw];
		$this->openOpening();
		$this->candidates->expects($this->never())->method('insertCandidate');
		$this->mail->expects($this->never())->method('sendToCandidate');
		$this->seenMail->expects($this->once())->method('remember');
		$this->client->expects($this->once())->method('markSeen')->with(1);

		$result = $this->service->fetchNow();

		$this->assertSame(1, $result['skipped']);
		$this->assertSame(0, $result['created']);
		$this->assertSame(1, $this->storedStatus['skipped']);
	}

	public function testAnAutomaticReplyToACandidateIsNotedWithoutNotifying(): void {
		$this->mailbox = [1 => self::raw(extraHeaders: "Auto-Submitted: auto-replied\r\n", to: 'jobs+c42.abcdefgh12345678@company.com')];
		$candidate = $this->candidate(42);
		$candidate->setReplyToken('abcdefgh12345678');
		$this->candidateMapper->method('find')->with(42)->willReturn($candidate);
		$member = new TeamMember();
		$member->setUid('manager');
		$member->setRole(TeamMember::ROLE_MANAGER);
		$this->teamMapper->method('findForOpening')->willReturn([$member]);

		$this->timeline->expects($this->once())->method('record')->with(42, TimelineEvent::TYPE_MAIL_RECEIVED, null, $this->callback(
			static fn (array $data): bool => $data['automatic'] === true && $data['reply'] === true,
		));
		$this->notifications->expects($this->never())->method('notify');
		$this->documents->expects($this->never())->method('add');
		$this->candidates->expects($this->never())->method('insertCandidate');

		$result = $this->service->fetchNow();

		$this->assertSame(0, $result['skipped']);
	}

	public function testAHumanReplyStillNotifiesTheManagers(): void {
		$this->mailbox = [1 => self::raw(to: 'jobs+c42.abcdefgh12345678@company.com')];
		$candidate = $this->candidate(42);
		$candidate->setReplyToken('abcdefgh12345678');
		$this->candidateMapper->method('find')->with(42)->willReturn($candidate);
		$member = new TeamMember();
		$member->setUid('manager');
		$member->setRole(TeamMember::ROLE_MANAGER);
		$this->teamMapper->method('findForOpening')->willReturn([$member]);

		$this->timeline->expects($this->once())->method('record')->with(42, TimelineEvent::TYPE_MAIL_RECEIVED, null, $this->callback(
			static fn (array $data): bool => !isset($data['automatic']),
		));
		$this->notifications->expects($this->once())->method('notify')->with(['manager']);

		$this->service->fetchNow();
	}

	public static function authenticationResults(): array {
		return [
			'dmarc fail' => ["Authentication-Results: mx.company.com; dmarc=fail header.from=example.com\r\n", false],
			'spf fail without dkim' => ["Authentication-Results: mx.company.com; spf=fail smtp.mailfrom=example.com\r\n", false],
			'spf fail with dkim pass' => ["Authentication-Results: mx.company.com; spf=fail; dkim=pass\r\n", true],
			'dmarc pass' => ["Authentication-Results: mx.company.com; spf=pass; dkim=pass; dmarc=pass\r\n", true],
			'no header' => ['', true],
		];
	}

	#[DataProvider('authenticationResults')]
	public function testForgedSendersGetACandidateButNoAcknowledgement(string $headers, bool $acknowledged): void {
		$this->mailbox = [1 => self::raw(extraHeaders: $headers)];
		$this->openOpening();
		$this->candidates->expects($this->once())->method('insertCandidate')->willReturn($this->candidate());
		$this->mail->expects($acknowledged ? $this->once() : $this->never())->method('sendToCandidate');

		$this->assertSame(1, $this->service->fetchNow()['created']);
	}

	public function testTheDailyCapSuppressesTheAcknowledgement(): void {
		$this->mailbox = [1 => self::raw()];
		$this->openOpening();
		$this->candidates->method('insertCandidate')->willReturn($this->candidate());
		$this->mail->expects($this->never())->method('sendToCandidate');
		$guard = $this->createMock(AutoAckGuard::class);
		$guard->expects($this->once())->method('tryAcquire')->willReturn(false);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-10T12:00:00+00:00'));

		$this->assertSame(1, $this->serviceWith($guard, $time)->fetchNow()['created']);
	}

	public function testDuplicatesAreNeitherCreatedNorSkipped(): void {
		$this->mailbox = [1 => self::raw()];
		$this->ledger['<m1@example.com>'] = ['done' => true, 'attempts' => 0, 'candidateId' => 55, 'error' => null, 'quarantined' => false];
		$this->candidates->expects($this->never())->method('insertCandidate');
		$this->client->expects($this->once())->method('markSeen');

		$result = $this->service->fetchNow();

		$this->assertSame(0, $result['created']);
		$this->assertSame(0, $result['skipped']);
	}

	public function testDisabledIntakeReportsZeroWithoutConnecting(): void {
		$config = $this->createMock(ConfigService::class);
		$config->method('isImapEnabled')->willReturn(false);
		$service = new IngestionService($config, $this->openingMapper, $this->candidateMapper, $this->seenMail, $this->teamMapper, $this->candidates, $this->documents, $this->mail, $this->timeline, $this->notifications, $this->createMock(AiService::class), $this->createMock(ITimeFactory::class), new NullLogger(), $this->guard);

		$result = $service->fetchNow();

		$this->assertSame(0, $result['fetched']);
		$this->assertSame('Recruiting-Failed', $result['quarantineFolder']);
	}

	private static function rawWithAttachments(): string {
		return "From: Jane Doe <jane@example.com>\r\n"
			. "To: jobs+backend-dev@company.com\r\n"
			. "Message-ID: <cv@example.com>\r\n"
			. "Subject: Application\r\n"
			. "MIME-Version: 1.0\r\n"
			. "Content-Type: multipart/mixed; boundary=b\r\n"
			. "\r\n"
			. "--b\r\nContent-Type: text/plain\r\n\r\nHello.\r\n"
			. "--b\r\nContent-Type: application/pdf; name=cv.pdf\r\nContent-Disposition: attachment; filename=cv.pdf\r\n\r\n%PDF-CV\r\n"
			. "--b\r\nContent-Type: application/pdf; name=refs.pdf\r\nContent-Disposition: attachment; filename=refs.pdf\r\n\r\n%PDF-REFS\r\n"
			. "--b--\r\n";
	}

	private function document(int $id, string $name, string $content): Document {
		$document = new Document();
		$document->setId($id);
		$document->setName($name);
		$document->setMime('application/pdf');
		$document->setSize(strlen($content));
		return $document;
	}

	public function testARetryAfterAFailureBetweenInsertAndLedgerResumesOnTheSameCandidate(): void {
		$this->mailbox = [1 => self::raw()];
		$this->openOpening();
		$candidate = $this->candidate();
		$this->candidates->expects($this->once())->method('insertCandidate')->willReturn($candidate);
		$this->candidateMapper->method('find')->with(55)->willReturn($candidate);
		$timelineCalls = 0;
		$this->timeline->method('record')->willReturnCallback(function () use (&$timelineCalls) {
			if ($timelineCalls++ === 0) {
				throw new DbException('connection lost');
			}
			return new TimelineEvent();
		});
		$this->timeline->method('eventsFor')->willReturn([]);
		// One acknowledgement, on the run that completes
		$this->mail->expects($this->once())->method('sendToCandidate');

		$first = $this->service->fetchNow();
		$this->assertSame('connection lost', $first['error']);
		$this->assertSame(55, $this->ledger['<m1@example.com>']['candidateId']);
		$this->assertFalse($this->ledger['<m1@example.com>']['done']);

		$second = $this->service->fetchNow();

		$this->assertSame(1, $second['created']);
		$this->assertNull($second['error']);
		$this->assertSame(2, $timelineCalls);
		$this->assertTrue($this->ledger['<m1@example.com>']['done']);
	}

	public function testAResumedApplicationSkipsTheStepsAlreadyDone(): void {
		$this->mailbox = [1 => self::rawWithAttachments()];
		$candidate = $this->candidate();
		$this->priorAttempts('<cv@example.com>', 1, 55);
		$this->candidateMapper->method('find')->with(55)->willReturn($candidate);
		$event = new TimelineEvent();
		$event->setType(TimelineEvent::TYPE_MAIL_RECEIVED);
		$this->timeline->method('eventsFor')->with(55)->willReturn([$event]);
		$stored = $this->document(1, 'cv.pdf', "%PDF-CV\r\n");
		$this->documents->method('listFor')->with(55)->willReturn([$stored]);
		$refs = $this->document(2, 'refs.pdf', "%PDF-REFS\r\n");

		$this->candidates->expects($this->never())->method('insertCandidate');
		$this->timeline->expects($this->never())->method('record');
		$this->documents->expects($this->once())->method('add')
			->with($candidate, 'refs.pdf', 'application/pdf', "%PDF-REFS\r\n", null, false)->willReturn($refs);
		// The extraction (and the AI intake after it) covers both documents
		$this->documents->expects($this->once())->method('queueTextExtraction')->with([$stored, $refs], 55)->willReturn(true);
		$this->ai->expects($this->never())->method('scheduleIntakeAnalysis');

		$this->assertSame(1, $this->service->fetchNow()['created']);
	}

	public function testAResumeOntoADeletedCandidateCreatesANewOne(): void {
		$this->mailbox = [1 => self::raw()];
		$this->priorAttempts('<m1@example.com>', 1, 55);
		$this->candidateMapper->method('find')->willThrowException(new DoesNotExistException('purged'));
		$this->candidates->expects($this->once())->method('insertCandidate')->willReturn($this->candidate(60));

		$this->service->fetchNow();

		$this->assertSame(60, $this->ledger['<m1@example.com>']['candidateId']);
	}

	public function testWithoutExtractableDocumentsTheIntakeAnalysisIsScheduledAtOnce(): void {
		$this->mailbox = [1 => self::raw()];
		$candidate = $this->candidate();
		$this->candidates->method('insertCandidate')->willReturn($candidate);
		$this->documents->method('queueTextExtraction')->willReturn(false);
		$this->ai->expects($this->once())->method('scheduleIntakeAnalysis')->with($candidate);

		$this->service->fetchNow();
	}

	public function testAttachmentsAreStoredWithoutInlineExtraction(): void {
		$this->mailbox = [1 => self::rawWithAttachments()];
		$this->candidates->method('insertCandidate')->willReturn($this->candidate());
		$this->documents->expects($this->exactly(2))->method('add')
			->with($this->anything(), $this->anything(), 'application/pdf', $this->anything(), null, false)
			->willReturnOnConsecutiveCalls($this->document(1, 'cv.pdf', 'x'), $this->document(2, 'refs.pdf', 'y'));
		$this->documents->expects($this->once())->method('queueTextExtraction')->with($this->countOf(2), 55)->willReturn(true);
		$this->ai->expects($this->never())->method('scheduleIntakeAnalysis');

		$this->service->fetchNow();
	}

	public function testAnOversizedMailIsQuarantinedWithoutDownloadingIt(): void {
		$this->mailbox = [4 => 'never fetched'];
		$this->client->method('fetchSize')->with(4)->willReturn(IngestionService::MAX_MESSAGE_SIZE + 1);
		$this->client->expects($this->never())->method('fetchMessage');
		$this->client->expects($this->once())->method('fetchHeader')->with(4)
			->willReturn("From: Jane <jane@example.com>\r\nMessage-ID: <huge@example.com>\r\nSubject: Portfolio\r\n\r\n");
		$this->client->expects($this->once())->method('markSeen')->with(4);
		$this->client->method('ensureMailbox')->willReturn('Recruiting-Failed');
		$this->client->expects($this->once())->method('moveMessage')->with(4, 'Recruiting-Failed');
		$this->candidates->expects($this->never())->method('insertCandidate');

		$result = $this->service->fetchNow();

		$this->assertSame(0, $result['fetched']);
		$this->assertTrue($this->ledger['<huge@example.com>']['quarantined']);
		$this->assertStringContainsString('larger than 30 MB', (string)$this->ledger['<huge@example.com>']['error']);
	}

	public function testAMailAtTheSizeLimitIsIngested(): void {
		$this->mailbox = [1 => self::raw()];
		$this->client->method('fetchSize')->willReturn(IngestionService::MAX_MESSAGE_SIZE);
		$this->candidates->expects($this->once())->method('insertCandidate')->willReturn($this->candidate());

		$this->assertSame(1, $this->service->fetchNow()['created']);
	}

	public function testLatin1HeadersBecomeValidUtf8BeforeAnythingIsStored(): void {
		$this->mailbox = [1 => "From: J\xF6rg M\xFCller <joerg@example.com>\r\n"
			. "To: jobs@company.com\r\n"
			. "Message-ID: <latin1@example.com>\r\n"
			. "Subject: Bewerbung f\xFCr die Stelle\r\n"
			. "Content-Type: text/plain\r\n"
			. "\r\n"
			. "Gr\xFC\xDFe\r\n"];
		$this->candidates->expects($this->once())->method('insertCandidate')
			->with(null, 'Jörg Müller', 'joerg@example.com')->willReturn($this->candidate());
		$this->timeline->expects($this->once())->method('record')->with(55, TimelineEvent::TYPE_MAIL_RECEIVED, null, [
			'subject' => 'Bewerbung für die Stelle',
			'body' => 'Grüße',
		]);

		$this->assertSame(1, $this->service->fetchNow()['created']);
	}
}
