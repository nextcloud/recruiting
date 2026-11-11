<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\SeenMailMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Imap\ImapClient;
use OCA\Recruiting\Imap\ImapException;
use OCA\Recruiting\Imap\MailClassifier;
use OCA\Recruiting\Imap\MimeParser;
use OCA\Recruiting\Imap\ParsedMail;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Files\GenericFileException;
use OCP\Files\LockNotAcquiredException;
use OCP\Files\NotPermittedException as FilesNotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Fetches the dedicated applications mailbox and turns every unseen mail
 * into a candidate (spec §4.2). Plus-addressing or a [slug] subject tag
 * routes into the matching opening; everything else lands in triage.
 * Automatic mail (bounces, autoresponders, lists) never becomes a candidate.
 *
 * A run works through the unseen mails for a fixed time. A mail that fails
 * with an error that may pass (database, storage) stays unseen and ends the
 * run; any other failure is retried on the next runs and, after the third
 * failed attempt, the mail is moved to the quarantine folder.
 */
class IngestionService {
	public const QUARANTINE_FOLDER = 'Recruiting-Failed';
	public const MAX_ATTEMPTS = 3;
	private const TIME_BUDGET_SECONDS = 60;
	private const MAX_MESSAGES_PER_RUN = 500;
	private const BODY_SNIPPET_LENGTH = 20_000;

	private const OUTCOME_CREATED = 'created';
	private const OUTCOME_REPLY = 'reply';
	private const OUTCOME_DUPLICATE = 'duplicate';
	private const OUTCOME_SKIPPED = 'skipped';

	/** Failures that may pass by themselves: the mail is retried, not counted */
	private const TRANSIENT_EXCEPTIONS = [
		DbException::class,
		'Doctrine\\DBAL\\Exception',
		FilesNotPermittedException::class,
		StorageNotAvailableException::class,
		GenericFileException::class,
		LockNotAcquiredException::class,
		LockedException::class,
	];

	public function __construct(
		private ConfigService $config,
		private OpeningMapper $openingMapper,
		private CandidateMapper $candidateMapper,
		private SeenMailMapper $seenMail,
		private TeamMemberMapper $teamMapper,
		private CandidateService $candidates,
		private DocumentService $documents,
		private MailService $mail,
		private TimelineService $timeline,
		private NotificationService $notifications,
		private AiService $ai,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private AutoAckGuard $autoAckGuard,
	) {
	}

	/**
	 * Run one intake pass. `error` in the result is set when a temporary
	 * failure ended the run early; the remaining mails stay unseen.
	 *
	 * @return array{fetched: int, created: int, skipped: int, quarantined: int, quarantineFolder: string, error: ?string}
	 * @throws ImapException when the mailbox is unreachable
	 */
	public function fetchNow(): array {
		$previous = $this->config->getIngestionStatus();
		$result = [
			'fetched' => 0,
			'created' => 0,
			'skipped' => 0,
			'quarantined' => (int)($previous['quarantined'] ?? 0),
			'quarantineFolder' => (string)($previous['quarantineFolder'] ?? self::QUARANTINE_FOLDER),
			'error' => null,
		];
		if (!$this->config->isImapEnabled()) {
			return $result;
		}
		$imap = $this->config->getImapConfig();
		if ($imap['host'] === '' || $imap['user'] === '') {
			return $result;
		}

		try {
			$this->fetchFromMailbox($imap, $result);
		} catch (\Exception $e) {
			// Recorded so the triage view can warn — a silently failing
			// mailbox is lost applications
			$result['error'] = $e->getMessage();
			$this->recordRun($result);
			throw $e;
		}
		$this->recordRun($result);
		return $result;
	}

	/**
	 * @param array{host: string, port: int, security: string, user: string, password: string} $imap
	 * @param array{fetched: int, created: int, skipped: int, quarantined: int, quarantineFolder: string, error: ?string} $result
	 */
	private function fetchFromMailbox(array $imap, array &$result): void {
		$client = $this->createClient($imap['host'], $imap['port'], $imap['security']);
		$client->connect();
		try {
			$client->login($imap['user'], $imap['password']);
			$client->selectInbox();

			$uids = array_slice($client->searchUnseen(), 0, self::MAX_MESSAGES_PER_RUN);
			$startedAt = $this->timeFactory->getTime();
			foreach ($uids as $uid) {
				if ($this->timeFactory->getTime() - $startedAt >= self::TIME_BUDGET_SECONDS) {
					break;
				}
				$raw = $client->fetchMessage($uid);
				$result['fetched']++;
				try {
					$outcome = $this->processMessage($raw);
				} catch (\Exception $e) {
					if (self::isTransient($e)) {
						// Leave the mail unseen; the next run retries it
						$this->logger->warning('Mail ingestion stopped by a temporary error (UID ' . $uid . '): ' . $e->getMessage(), ['exception' => $e]);
						$result['error'] = $e->getMessage();
						break;
					}
					$this->handleFailure($client, $uid, $raw, $e, $result);
					continue;
				}
				if ($outcome === self::OUTCOME_CREATED) {
					$result['created']++;
				} elseif ($outcome === self::OUTCOME_SKIPPED) {
					$result['skipped']++;
				}
				$client->markSeen($uid);
			}
			$result['quarantined'] = $this->quarantineCount($client, $result['quarantineFolder']);
		} finally {
			$client->logout();
		}
	}

	/**
	 * A failure that will not pass by itself: count the attempt and leave the
	 * mail unseen for a retry, or quarantine it after the last attempt.
	 *
	 * @param array{fetched: int, created: int, skipped: int, quarantined: int, quarantineFolder: string, error: ?string} $result
	 * @throws ImapException
	 */
	private function handleFailure(ImapClient $client, int $uid, string $raw, \Exception $e, array &$result): void {
		$key = $this->failureKey($raw);
		$attempts = $this->seenMail->recordFailure($key, $e->getMessage());
		$this->logger->error('Failed to ingest application mail (UID ' . $uid . ', attempt ' . $attempts . ' of ' . self::MAX_ATTEMPTS . '): ' . $e->getMessage(), ['exception' => $e]);
		if ($attempts < self::MAX_ATTEMPTS) {
			return;
		}

		// Seen first: should the move fail, the mail must not be retried forever
		$client->markSeen($uid);
		$this->seenMail->markQuarantined($key);
		try {
			$result['quarantineFolder'] = $client->ensureMailbox(self::QUARANTINE_FOLDER);
			$client->moveMessage($uid, $result['quarantineFolder']);
		} catch (ImapException $moveFailure) {
			$this->logger->error('Could not move the failed application mail (UID ' . $uid . ') to "' . self::QUARANTINE_FOLDER . '": ' . $moveFailure->getMessage() . ' — it stays in the inbox, marked as read.');
		}
	}

	/**
	 * The ledger key of a mail: its Message-ID, or a content hash for mails
	 * without one.
	 */
	private function failureKey(string $raw): string {
		try {
			$messageId = (new MimeParser())->parse($raw)->messageId;
		} catch (\Throwable) {
			$messageId = '';
		}
		return $messageId !== '' ? $messageId : 'sha256:' . hash('sha256', $raw);
	}

	private function quarantineCount(ImapClient $client, string $folder): int {
		try {
			return $client->messageCount($folder);
		} catch (ImapException) {
			// No such folder: nothing was ever quarantined
			return 0;
		}
	}

	/**
	 * Whether an exception is (or wraps) a failure that may pass by itself.
	 */
	public static function isTransient(\Throwable $e): bool {
		for ($current = $e; $current !== null; $current = $current->getPrevious()) {
			$types = array_merge([get_class($current)], class_parents($current) ?: [], class_implements($current) ?: []);
			if (array_intersect(self::TRANSIENT_EXCEPTIONS, $types) !== []) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array{fetched: int, created: int, skipped: int, quarantined: int, quarantineFolder: string, error: ?string} $result
	 */
	private function recordRun(array $result): void {
		$this->config->setIngestionStatus([
			'ranAt' => $this->timeFactory->getDateTime()->format(\DateTimeInterface::ATOM),
			'fetched' => $result['fetched'],
			'created' => $result['created'],
			'skipped' => $result['skipped'],
			'quarantined' => $result['quarantined'],
			'quarantineFolder' => $result['quarantineFolder'],
			'error' => $result['error'],
		]);
	}

	/**
	 * Verify the IMAP settings from the admin screen without ingesting.
	 *
	 * @throws ImapException
	 */
	public function testConnection(string $host, int $port, string $security, string $user, string $password): void {
		$client = $this->createClient($host, $port, $security);
		$client->connect();
		try {
			$client->login($user, $password);
			$client->selectInbox();
		} finally {
			$client->logout();
		}
	}

	protected function createClient(string $host, int $port, string $security): ImapClient {
		return new ImapClient($host, $port, $security);
	}

	/**
	 * @return self::OUTCOME_* what became of the mail
	 */
	private function processMessage(string $raw): string {
		$parsed = (new MimeParser())->parse($raw);
		$classifier = new MailClassifier();

		// Idempotency: a crash between processing and the IMAP seen-flag would
		// re-deliver this mail on the next run. The Message-ID ledger is
		// written after successful processing (at-least-once, never lost).
		if ($this->seenMail->hasSeen($parsed->messageId)) {
			$this->logger->info('Skipping already-ingested mail ' . $parsed->messageId);
			return self::OUTCOME_DUPLICATE;
		}

		$automatic = $classifier->isAutoGenerated($parsed);

		// A reply to one of our own mails (per-candidate Reply-To) belongs on
		// the existing candidate's timeline, not in triage as a "new" application.
		$existing = $this->matchReply($parsed);
		if ($existing !== null) {
			$this->appendReply($existing, $parsed, $automatic);
			$this->seenMail->remember($parsed->messageId);
			return self::OUTCOME_REPLY;
		}

		if ($automatic) {
			// Bounces and autoresponders are no applications, and acknowledging
			// them would start a mail loop
			$this->logger->info('Skipping automatic mail from ' . $parsed->fromEmail . ': ' . $parsed->subject);
			$this->seenMail->remember($parsed->messageId);
			return self::OUTCOME_SKIPPED;
		}

		$opening = $this->matchOpening($parsed);

		$name = trim($parsed->fromName);
		$candidate = $this->candidates->insertCandidate(
			$opening?->getId(),
			$name,
			filter_var($parsed->fromEmail, FILTER_VALIDATE_EMAIL) !== false ? $parsed->fromEmail : '',
			'',
			Candidate::SOURCE_EMAIL,
			null,
		);

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_MAIL_RECEIVED, null, [
			'subject' => $parsed->subject,
			'body' => mb_substr($parsed->textBody, 0, self::BODY_SNIPPET_LENGTH),
		]);

		foreach ($parsed->attachments as $attachment) {
			try {
				$this->documents->add($candidate, $attachment['name'], $attachment['mime'], $attachment['content'], null);
			} catch (ValidationException $e) {
				// Disallowed type or too large — skip just this attachment
				$this->logger->info('Skipped attachment "' . $attachment['name'] . '": ' . $e->getMessage());
			}
		}

		// Optional AI intake analysis: summary + contact extraction (spec §4.7)
		$this->ai->scheduleIntakeAnalysis($candidate);

		if ($opening !== null) {
			if ($classifier->isForged($parsed)) {
				// Acknowledging a spoofed From would send backscatter to a stranger
				$this->logger->info('Not acknowledging mail from ' . $parsed->fromEmail . ': Authentication-Results show a forged sender');
			} else {
				$this->sendAutoAck($opening, $candidate);
			}
		}

		$this->seenMail->remember($parsed->messageId);
		return self::OUTCOME_CREATED;
	}

	/**
	 * The opening's receipt confirmation, when enabled and within today's cap.
	 * A failed send is logged and never fails the intake.
	 */
	private function sendAutoAck(Opening $opening, Candidate $candidate): void {
		if (!$opening->getAutoAck() || $candidate->getEmail() === '') {
			return;
		}
		if (!$this->autoAckGuard->tryAcquire($opening)) {
			return;
		}
		try {
			$draft = $this->mail->preview($candidate, $opening->getTitle(), MailTemplate::TYPE_RECEIPT, null, null);
			$this->mail->sendToCandidate($candidate, $draft['subject'], $draft['body'], null);
		} catch (\Exception $e) {
			$this->logger->warning('Auto-acknowledgement failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Materialise a public web-application submission (spec §4.13) through the
	 * same pipeline an emailed application uses: candidate row, a timeline entry
	 * carrying the cover letter (so AI intake still finds the body), the CV as a
	 * document, optional AI analysis, and the opening's auto-acknowledgement.
	 *
	 * The web form has already validated its inputs; everything here mirrors
	 * {@see processMessage()} minus the IMAP/idempotency machinery.
	 *
	 * @param array{name?:string,mime?:string,content?:string}|null $cv
	 * @param ?string $language a known language code (see LanguageService)
	 * @return Candidate the created candidate
	 */
	public function ingestWebApplication(Opening $opening, string $name, string $email, string $coverLetter, ?array $cv, ?string $language = null): Candidate {
		$candidate = $this->candidates->insertCandidate(
			$opening->getId(),
			$name,
			filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
			'',
			Candidate::SOURCE_WEB,
			null,
			null,
			$language,
		);

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_WEB_APPLICATION, null, [
			'subject' => 'Application for ' . $opening->getTitle(),
			'body' => mb_substr(trim($coverLetter), 0, self::BODY_SNIPPET_LENGTH),
		]);

		if ($cv !== null && (string)($cv['content'] ?? '') !== '') {
			try {
				$this->documents->add($candidate, (string)($cv['name'] ?? 'CV'), (string)($cv['mime'] ?? ''), (string)$cv['content'], null);
			} catch (ValidationException $e) {
				$this->logger->info('Skipped web-application CV: ' . $e->getMessage());
			}
		}

		$this->ai->scheduleIntakeAnalysis($candidate);
		$this->sendAutoAck($opening, $candidate);

		return $candidate;
	}

	/**
	 * Match the per-candidate Reply-To ("jobs+c{id}.{token}@…", spec §4.2).
	 * The token must match the stored one — the candidate id alone is
	 * guessable and must not let outsiders write onto someone's timeline.
	 */
	private function matchReply(ParsedMail $parsed): ?Candidate {
		foreach ($parsed->recipients as $recipient) {
			if (!preg_match('/\+c(\d+)\.([a-z0-9]+)@/i', $recipient, $m)) {
				continue;
			}
			try {
				$candidate = $this->candidateMapper->find((int)$m[1]);
			} catch (DoesNotExistException) {
				continue;
			}
			$token = $candidate->getReplyToken();
			if ($token === null || $token === '' || !hash_equals($token, strtolower($m[2]))) {
				continue;
			}
			if ($candidate->getAnonymizedAt() !== null) {
				continue;
			}
			return $candidate;
		}
		return null;
	}

	/**
	 * Append a candidate's reply to their timeline, store its attachments,
	 * and tell the hiring managers. An automatic reply (out-of-office,
	 * bounce) is only noted on the timeline: no attachments, no notification.
	 */
	private function appendReply(Candidate $candidate, ParsedMail $parsed, bool $automatic): void {
		$data = [
			'subject' => $parsed->subject,
			'body' => mb_substr($parsed->textBody, 0, self::BODY_SNIPPET_LENGTH),
			'reply' => true,
		];
		if ($automatic) {
			$data['automatic'] = true;
		}
		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_MAIL_RECEIVED, null, $data);
		if ($automatic) {
			return;
		}

		foreach ($parsed->attachments as $attachment) {
			try {
				$this->documents->add($candidate, $attachment['name'], $attachment['mime'], $attachment['content'], null);
			} catch (ValidationException $e) {
				$this->logger->info('Skipped attachment "' . $attachment['name'] . '": ' . $e->getMessage());
			}
		}

		$openingId = $candidate->getOpeningId();
		if ($openingId === null) {
			return;
		}
		$managers = [];
		foreach ($this->teamMapper->findForOpening($openingId) as $member) {
			if ($member->getRole() === TeamMember::ROLE_MANAGER) {
				$managers[] = $member->getUid();
			}
		}
		$this->notifications->notify($managers, NotificationService::SUBJECT_CANDIDATE_REPLIED, $candidate, null);
	}

	/**
	 * Route by plus-address (jobs+slug@…) first, then by a [slug] tag in
	 * the subject. Only open openings receive applications directly.
	 */
	private function matchOpening(ParsedMail $parsed): ?Opening {
		foreach ($parsed->recipients as $recipient) {
			if (preg_match('/^[^+@]+\+([^@]+)@/', $recipient, $m)) {
				$opening = $this->openingMapper->findByMailSlug(strtolower($m[1]));
				if ($opening !== null && $opening->getStatus() === Opening::STATUS_OPEN) {
					return $opening;
				}
			}
		}
		// Every [tag] is a candidate, not just the first: relays and mail
		// gateways love to prepend things like "[EXTERNAL]".
		if (preg_match_all('/\[([a-z0-9\-]+)\]/i', $parsed->subject, $matches)) {
			foreach ($matches[1] as $tag) {
				$opening = $this->openingMapper->findByMailSlug(strtolower($tag));
				if ($opening !== null && $opening->getStatus() === Opening::STATUS_OPEN) {
					return $opening;
				}
			}
		}
		return null;
	}
}
