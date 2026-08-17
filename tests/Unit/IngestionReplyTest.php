<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\SeenMailMapper;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Imap\ImapClient;
use OCA\Recruiting\Service\AiService;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\IngestionService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Reply threading (spec §4.2): a mail to the per-candidate Reply-To address
 * lands on the existing candidate's timeline; everything else stays an
 * application. Plus the Message-ID ledger that makes ingestion idempotent.
 */
class IngestionReplyTest extends TestCase {
	private ConfigService $config;
	private CandidateMapper $candidateMapper;
	private SeenMailMapper $seenMail;
	private TeamMemberMapper $teamMapper;
	private CandidateService $candidates;
	private DocumentService $documents;
	private TimelineService $timeline;
	private NotificationService $notifications;
	private IngestionService $service;
	private ImapClient&MockObject $client;

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

		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->seenMail = $this->createMock(SeenMailMapper::class);
		$this->teamMapper = $this->createMock(TeamMemberMapper::class);
		$this->candidates = $this->createMock(CandidateService::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->notifications = $this->createMock(NotificationService::class);

		$this->client = $this->createMock(ImapClient::class);
		$client = $this->client;

		$this->service = new class($client, $this->config, $this->createMock(OpeningMapper::class), $this->candidateMapper, $this->seenMail, $this->teamMapper, $this->candidates, $this->documents, $this->createMock(MailService::class), $this->timeline, $this->notifications, $this->createMock(AiService::class), $this->timeFactoryMock(), new NullLogger(), ) extends IngestionService {
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

		$this->teamMapper->method('findForOpening')->willReturn([]);
	}

	private function timeFactoryMock(): \OCP\AppFramework\Utility\ITimeFactory {
		$factory = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
		$factory->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-08-17T12:00:00+00:00'));
		return $factory;
	}

	private function primeMailbox(string $raw): void {
		$this->client->method('searchUnseen')->willReturn([1]);
		$this->client->method('fetchMessage')->willReturn($raw);
	}

	private function candidate(int $id, ?string $replyToken): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setDisplayName('Jane Doe');
		$candidate->setEmail('jane@example.com');
		$candidate->setOpeningId(7);
		if ($replyToken !== null) {
			$candidate->setReplyToken($replyToken);
		}
		return $candidate;
	}

	public function testReplyLandsOnExistingCandidate(): void {
		$this->primeMailbox(
			"From: Jane Doe <jane@example.com>\r\n"
			. "To: jobs+c42.abcdefgh12345678@company.com\r\n"
			. "Message-ID: <reply-1@example.com>\r\n"
			. "Subject: Re: Interview invitation\r\n"
			. "Content-Type: text/plain; charset=utf-8\r\n"
			. "\r\n"
			. "Tuesday works for me.\r\n",
		);
		$this->candidateMapper->method('find')->with(42)
			->willReturn($this->candidate(42, 'abcdefgh12345678'));

		// The reply is appended to the timeline …
		$this->timeline->expects($this->once())->method('record')
			->with(42, TimelineEvent::TYPE_MAIL_RECEIVED, null, $this->callback(
				static fn (array $data): bool => ($data['reply'] ?? false) === true
					&& str_contains($data['body'], 'Tuesday works'),
			));
		// … and no new candidate is created
		$this->candidates->expects($this->never())->method('insertCandidate');
		// … and the mail is remembered for idempotency
		$this->seenMail->expects($this->once())->method('remember')->with('<reply-1@example.com>');

		$this->service->fetchNow();
	}

	public function testWrongTokenBecomesNewApplication(): void {
		$this->primeMailbox(
			"From: Mallory <mallory@example.com>\r\n"
			. "To: jobs+c42.wrongtoken0000000@company.com\r\n"
			. "Subject: Fake reply\r\n"
			. "Content-Type: text/plain; charset=utf-8\r\n"
			. "\r\n"
			. "Guessing the id must not be enough.\r\n",
		);
		$this->candidateMapper->method('find')->with(42)
			->willReturn($this->candidate(42, 'abcdefgh12345678'));

		// Token mismatch: treated as a regular (triage) application
		$created = $this->candidate(99, null);
		$this->candidates->expects($this->once())->method('insertCandidate')->willReturn($created);
		$this->timeline->expects($this->once())->method('record')
			->with(99, TimelineEvent::TYPE_MAIL_RECEIVED, null, $this->callback(
				static fn (array $data): bool => !isset($data['reply']),
			));

		$this->service->fetchNow();
	}

	public function testDeletedCandidateReplyFallsThrough(): void {
		$this->primeMailbox(
			"From: Jane Doe <jane@example.com>\r\n"
			. "To: jobs+c42.abcdefgh12345678@company.com\r\n"
			. "Subject: Re: Interview invitation\r\n"
			. "Content-Type: text/plain; charset=utf-8\r\n"
			. "\r\n"
			. "Hello again.\r\n",
		);
		$this->candidateMapper->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->candidates->expects($this->once())->method('insertCandidate')
			->willReturn($this->candidate(99, null));

		$this->service->fetchNow();
	}

	public function testSeenMessageIdIsSkipped(): void {
		$this->primeMailbox(
			"From: Jane Doe <jane@example.com>\r\n"
			. "To: jobs@company.com\r\n"
			. "Message-ID: <dup-1@example.com>\r\n"
			. "Subject: Application\r\n"
			. "Content-Type: text/plain; charset=utf-8\r\n"
			. "\r\n"
			. "Hello.\r\n",
		);
		$this->seenMail->method('hasSeen')->with('<dup-1@example.com>')->willReturn(true);

		$this->candidates->expects($this->never())->method('insertCandidate');
		$this->timeline->expects($this->never())->method('record');

		$result = $this->service->fetchNow();
		// Fetched, skipped as duplicate, still marked seen in the mailbox
		$this->assertSame(1, $result['fetched']);
	}
}
