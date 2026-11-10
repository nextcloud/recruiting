<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
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
		$this->teamMapper = $this->createMock(TeamMemberMapper::class);
		$this->candidates = $this->createMock(CandidateService::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->mail = $this->createMock(MailService::class);
		$this->mail->method('preview')->willReturn(['subject' => 'Received', 'body' => 'Thanks', 'to' => '']);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->guard = $this->createMock(AutoAckGuard::class);
		$this->guard->method('tryAcquire')->willReturn(true);

		$this->client = $this->createMock(ImapClient::class);
		$this->client->method('searchUnseen')->willReturnCallback(fn () => array_keys($this->mailbox));
		$this->client->method('fetchMessage')->willReturnCallback(fn (int $uid) => $this->mailbox[$uid]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->clock !== [] ? array_shift($this->clock) : 0);
		$time->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-11-10T12:00:00+00:00'));

		$this->service = $this->serviceWith($this->guard, $time);
	}

	private function serviceWith(AutoAckGuard $guard, ITimeFactory $time): IngestionService {
		return new class($this->client, $this->config, $this->openingMapper, $this->candidateMapper, $this->seenMail, $this->teamMapper, $this->candidates, $this->documents, $this->mail, $this->timeline, $this->notifications, $this->createMock(AiService::class), $time, new NullLogger(), $guard) extends IngestionService {
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
		$this->mailbox = array_fill_keys(range(1, 501), self::raw(messageId: ''));
		$this->seenMail->method('hasSeen')->willReturn(false);
		$this->candidates->method('insertCandidate')->willReturn($this->candidate());
		$this->client->expects($this->exactly(500))->method('fetchMessage');

		$this->assertSame(500, $this->service->fetchNow()['fetched']);
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
		$this->seenMail->expects($this->never())->method('recordFailure');
		$this->client->expects($this->never())->method('moveMessage');

		$result = $this->service->fetchNow();

		$this->assertSame($failure->getMessage(), $result['error']);
		$this->assertSame($failure->getMessage(), $this->storedStatus['error']);
		$this->assertSame(1, $result['fetched']);
	}

	public function testTransientClassificationRecognisesDoctrineByName(): void {
		$this->assertTrue(IngestionService::isTransient(new DbException('x')));
		$this->assertFalse(IngestionService::isTransient(new \RuntimeException('x')));
		$this->assertFalse(IngestionService::isTransient(new \OCA\Recruiting\Exception\NotPermittedException('app permission, not storage')));
	}

	public function testAPermanentFailureIsRetriedUnseenBeforeTheThirdAttempt(): void {
		$this->mailbox = [1 => self::raw(messageId: '<poison@x>'), 2 => self::raw(messageId: '<ok@x>')];
		$calls = 0;
		$this->candidates->method('insertCandidate')->willReturnCallback(function () use (&$calls) {
			if ($calls++ === 0) {
				throw new \RuntimeException('broken mail');
			}
			return $this->candidate();
		});
		$this->seenMail->expects($this->once())->method('recordFailure')->with('<poison@x>', 'broken mail')->willReturn(2);
		$this->client->expects($this->once())->method('markSeen')->with(2);
		$this->client->expects($this->never())->method('moveMessage');

		$result = $this->service->fetchNow();

		$this->assertSame(2, $result['fetched']);
		$this->assertSame(1, $result['created']);
		$this->assertNull($result['error']);
	}

	public function testTheThirdFailureQuarantinesTheMail(): void {
		$this->mailbox = [9 => self::raw(messageId: '<poison@x>')];
		$this->candidates->method('insertCandidate')->willThrowException(new \RuntimeException('broken mail'));
		$this->seenMail->method('recordFailure')->willReturn(3);
		$this->seenMail->expects($this->once())->method('markQuarantined')->with('<poison@x>');
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
		$this->candidates->method('insertCandidate')->willThrowException(new \RuntimeException('broken mail'));
		$this->seenMail->method('recordFailure')->willReturn(3);
		$this->client->expects($this->once())->method('markSeen')->with(9);
		$this->client->method('ensureMailbox')->willThrowException(new ImapException('NO permission denied'));

		$result = $this->service->fetchNow();

		$this->assertNull($result['error']);
	}

	public function testMailsWithoutMessageIdAreKeyedByContentHash(): void {
		$raw = self::raw(messageId: '');
		$this->mailbox = [1 => $raw];
		$this->candidates->method('insertCandidate')->willThrowException(new \RuntimeException('broken'));
		$this->seenMail->expects($this->once())->method('recordFailure')->with('sha256:' . hash('sha256', $raw))->willReturn(1);

		$this->service->fetchNow();
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
		$this->seenMail->method('hasSeen')->willReturn(true);
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
}
