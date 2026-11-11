<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Document;
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
 * A run works through the unseen mails for a fixed time. Every attempt at
 * a mail is counted in the ledger before the mail is processed, and the
 * candidate it creates is noted there right after the insert, so a retry
 * continues on that candidate instead of creating another one.
 *
 * A mail that fails with an error that may pass (database, storage) stays
 * unseen and ends the run; after twelve such attempts it is quarantined. Any
 * other failure is retried on the next runs and quarantined after the third
 * attempt. Mails above the size limit are quarantined without downloading.
 */
class IngestionService {
	public const QUARANTINE_FOLDER = 'Recruiting-Failed';
	public const MAX_ATTEMPTS = 3;
	/** Attempts after which a mail that keeps failing temporarily (or crashing) is quarantined */
	public const MAX_TRANSIENT_ATTEMPTS = 12;
	/** Larger mails are quarantined without being downloaded */
	public const MAX_MESSAGE_SIZE = 30 * 1024 * 1024;
	private const TIME_BUDGET_SECONDS = 60;
	private const MAX_MESSAGES_PER_RUN = 500;
	private const BODY_SNIPPET_LENGTH = 20_000;

	private const OUTCOME_CREATED = 'created';
	private const OUTCOME_REPLY = 'reply';
	private const OUTCOME_DUPLICATE = 'duplicate';
	private const OUTCOME_SKIPPED = 'skipped';

	/** Failures that may pass by themselves: the mail is retried on the next run */
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
				$size = $client->fetchSize($uid);
				if ($size > self::MAX_MESSAGE_SIZE) {
					$this->quarantineOversized($client, $uid, $size, $result);
					continue;
				}
				$raw = $client->fetchMessage($uid);
				$result['fetched']++;
				$parsed = self::tryParse($raw);
				$key = self::ledgerKey($parsed, $raw);
				$attempt = null;
				try {
					$attempt = $this->seenMail->beginAttempt($key);
					if ($attempt['done']) {
						$this->logger->info('Skipping already-ingested mail ' . $key);
						$outcome = self::OUTCOME_DUPLICATE;
					} elseif ($attempt['attempts'] > self::MAX_TRANSIENT_ATTEMPTS) {
						// The earlier attempts ended without a result: crashes or timeouts
						$this->logger->error('Giving up on application mail (UID ' . $uid . ') after ' . ($attempt['attempts'] - 1) . ' unfinished attempts');
						$this->quarantine($client, $uid, $key, 'Gave up after ' . ($attempt['attempts'] - 1) . ' unfinished attempts', $result);
						continue;
					} else {
						$outcome = $this->processMessage($parsed ?? (new MimeParser())->parse($raw), $key, $attempt['candidateId']);
					}
				} catch (\Exception $e) {
					if (!$this->handleFailure($client, $uid, $key, $attempt['attempts'] ?? null, $e, $result)) {
						break;
					}
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
	 * A failed attempt (already counted): leave the mail unseen for a retry,
	 * or quarantine it once its attempts are used up — three for a permanent
	 * failure, twelve for one that may pass.
	 *
	 * @param ?int $attempts the attempts so far, null when the ledger itself failed
	 * @param array{fetched: int, created: int, skipped: int, quarantined: int, quarantineFolder: string, error: ?string} $result
	 * @return bool whether the run goes on with the next mail
	 * @throws ImapException
	 */
	private function handleFailure(ImapClient $client, int $uid, string $key, ?int $attempts, \Exception $e, array &$result): bool {
		$transient = self::isTransient($e);
		if ($attempts === null) {
			// Nothing was counted or processed: the ledger itself is unreachable
			$this->logger->warning('Mail ingestion stopped by a temporary error (UID ' . $uid . '): ' . $e->getMessage(), ['exception' => $e]);
			$result['error'] = $e->getMessage();
			return false;
		}
		try {
			$this->seenMail->recordError($key, $e->getMessage());
		} catch (\Exception) {
			// The database may be what failed; the attempt is counted already
		}

		$limit = $transient ? self::MAX_TRANSIENT_ATTEMPTS : self::MAX_ATTEMPTS;
		if ($attempts < $limit) {
			if ($transient) {
				$this->logger->warning('Mail ingestion stopped by a temporary error (UID ' . $uid . ', attempt ' . $attempts . ' of ' . $limit . '): ' . $e->getMessage(), ['exception' => $e]);
				$result['error'] = $e->getMessage();
				return false;
			}
			$this->logger->error('Failed to ingest application mail (UID ' . $uid . ', attempt ' . $attempts . ' of ' . $limit . '): ' . $e->getMessage(), ['exception' => $e]);
			return true;
		}

		$this->logger->error('Failed to ingest application mail (UID ' . $uid . ', attempt ' . $attempts . ' of ' . $limit . ', giving up): ' . $e->getMessage(), ['exception' => $e]);
		$this->quarantine($client, $uid, $key, $e->getMessage(), $result);
		return true;
	}

	/**
	 * A mail above the size limit is quarantined without downloading it;
	 * only its header is read, for the ledger key.
	 *
	 * @param array{fetched: int, created: int, skipped: int, quarantined: int, quarantineFolder: string, error: ?string} $result
	 * @throws ImapException
	 */
	private function quarantineOversized(ImapClient $client, int $uid, int $size, array &$result): void {
		$header = $client->fetchHeader($uid);
		$key = self::ledgerKey(self::tryParse(rtrim($header, "\r\n") . "\r\n\r\n"), $header);
		$reason = sprintf('The mail is larger than %d MB (%d bytes)', intdiv(self::MAX_MESSAGE_SIZE, 1024 * 1024), $size);
		$this->logger->warning('Application mail (UID ' . $uid . ') not ingested: ' . $reason);
		$this->quarantine($client, $uid, $key, $reason, $result);
	}

	/**
	 * @param array{fetched: int, created: int, skipped: int, quarantined: int, quarantineFolder: string, error: ?string} $result
	 * @throws ImapException
	 */
	private function quarantine(ImapClient $client, int $uid, string $key, string $reason, array &$result): void {
		// Seen first: should the move fail, the mail must not be retried forever
		$client->markSeen($uid);
		$this->seenMail->markQuarantined($key, $reason);
		try {
			$result['quarantineFolder'] = $client->ensureMailbox(self::QUARANTINE_FOLDER);
			$client->moveMessage($uid, $result['quarantineFolder']);
		} catch (ImapException $moveFailure) {
			$this->logger->error('Could not move the failed application mail (UID ' . $uid . ') to "' . self::QUARANTINE_FOLDER . '": ' . $moveFailure->getMessage() . ' — it stays in the inbox, marked as read.');
		}
	}

	private static function tryParse(string $raw): ?ParsedMail {
		try {
			return (new MimeParser())->parse($raw);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * The ledger key of a mail: its Message-ID, or a content hash for mails
	 * without one.
	 */
	private static function ledgerKey(?ParsedMail $parsed, string $raw): string {
		$messageId = $parsed?->messageId ?? '';
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
	 * Turn one mail into a candidate (or a reply on one). With
	 * $resumeCandidateId an earlier attempt got as far as that candidate:
	 * the work continues there, and steps already done are skipped.
	 *
	 * @return self::OUTCOME_* what became of the mail
	 */
	private function processMessage(ParsedMail $parsed, string $key, ?int $resumeCandidateId): string {
		$classifier = new MailClassifier();
		$automatic = $classifier->isAutoGenerated($parsed);

		// A reply to one of our own mails (per-candidate Reply-To) belongs on
		// the existing candidate's timeline, not in triage as a "new" application.
		$existing = $this->matchReply($parsed);
		if ($existing !== null) {
			$this->appendReply($existing, $parsed, $automatic, $key, $resumeCandidateId === $existing->getId());
			$this->seenMail->remember($key);
			return self::OUTCOME_REPLY;
		}

		if ($automatic) {
			// Bounces and autoresponders are no applications, and acknowledging
			// them would start a mail loop
			$this->logger->info('Skipping automatic mail from ' . $parsed->fromEmail . ': ' . $parsed->subject);
			$this->seenMail->remember($key);
			return self::OUTCOME_SKIPPED;
		}

		$opening = $this->matchOpening($parsed);

		$candidate = $resumeCandidateId !== null ? $this->findCandidate($resumeCandidateId) : null;
		$resumed = $candidate !== null;
		if ($candidate !== null && $candidate->getAnonymizedAt() !== null) {
			$this->seenMail->remember($key);
			return self::OUTCOME_DUPLICATE;
		}
		if ($candidate === null) {
			$candidate = $this->candidates->insertCandidate(
				$opening?->getId(),
				trim($parsed->fromName),
				filter_var($parsed->fromEmail, FILTER_VALIDATE_EMAIL) !== false ? $parsed->fromEmail : '',
				'',
				Candidate::SOURCE_EMAIL,
				null,
			);
			$this->seenMail->attachCandidate($key, $candidate->getId());
		}

		if (!$resumed || !$this->hasEvent($candidate, TimelineEvent::TYPE_MAIL_RECEIVED)) {
			$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_MAIL_RECEIVED, null, [
				'subject' => $parsed->subject,
				'body' => mb_substr($parsed->textBody, 0, self::BODY_SNIPPET_LENGTH),
			]);
		}

		$documents = $this->storeAttachments($candidate, $parsed->attachments, $resumed, false);
		$this->scheduleIntake($candidate, $documents);

		if ($opening !== null) {
			if ($classifier->isForged($parsed)) {
				// Acknowledging a spoofed From would send backscatter to a stranger
				$this->logger->info('Not acknowledging mail from ' . $parsed->fromEmail . ': Authentication-Results show a forged sender');
			} else {
				$this->sendAutoAck($opening, $candidate);
			}
		}

		$this->seenMail->remember($key);
		return self::OUTCOME_CREATED;
	}

	/**
	 * Optional AI intake analysis: summary + contact extraction (spec §4.7).
	 * It needs the CV text, so with an extractable document it is scheduled
	 * by the extraction job once the text is there.
	 *
	 * @param Document[] $documents
	 */
	private function scheduleIntake(Candidate $candidate, array $documents): void {
		if (!$this->documents->queueTextExtraction($documents, $candidate->getId())) {
			$this->ai->scheduleIntakeAnalysis($candidate);
		}
	}

	/**
	 * Store a mail's attachments. When resuming, an attachment stored by an
	 * earlier attempt (same name and size) is not stored again.
	 *
	 * @param array<int, array{name: string, mime: string, content: string}> $attachments
	 * @return Document[] the documents of these attachments
	 */
	private function storeAttachments(Candidate $candidate, array $attachments, bool $resumed, bool $queueExtraction): array {
		$stored = $resumed ? $this->documents->listFor($candidate->getId()) : [];
		$documents = [];
		foreach ($attachments as $attachment) {
			$name = DocumentService::sanitizeName($attachment['name']);
			foreach ($stored as $index => $document) {
				if ($document->getName() === $name && $document->getSize() === strlen($attachment['content'])) {
					$documents[] = $document;
					unset($stored[$index]);
					continue 2;
				}
			}
			try {
				$documents[] = $this->documents->add($candidate, $attachment['name'], $attachment['mime'], $attachment['content'], null, $queueExtraction);
			} catch (ValidationException $e) {
				// Disallowed type or too large — skip just this attachment
				$this->logger->info('Skipped attachment "' . $attachment['name'] . '": ' . $e->getMessage());
			}
		}
		return $documents;
	}

	private function findCandidate(int $id): ?Candidate {
		try {
			return $this->candidateMapper->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	private function hasEvent(Candidate $candidate, string $type): bool {
		foreach ($this->timeline->eventsFor($candidate->getId()) as $event) {
			if ($event->getType() === $type) {
				return true;
			}
		}
		return false;
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
	 * @return Candidate the created candidate
	 */
	public function ingestWebApplication(Opening $opening, string $name, string $email, string $coverLetter, ?array $cv): Candidate {
		$candidate = $this->candidates->insertCandidate(
			$opening->getId(),
			$name,
			filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
			'',
			Candidate::SOURCE_WEB,
			null,
		);

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_WEB_APPLICATION, null, [
			'subject' => 'Application for ' . $opening->getTitle(),
			'body' => mb_substr(trim($coverLetter), 0, self::BODY_SNIPPET_LENGTH),
		]);

		$documents = [];
		if ($cv !== null && (string)($cv['content'] ?? '') !== '') {
			try {
				$documents[] = $this->documents->add($candidate, (string)($cv['name'] ?? 'CV'), (string)($cv['mime'] ?? ''), (string)$cv['content'], null, false);
			} catch (ValidationException $e) {
				$this->logger->info('Skipped web-application CV: ' . $e->getMessage());
			}
		}

		$this->scheduleIntake($candidate, $documents);
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
	 *
	 * The ledger notes the candidate once the timeline entry exists; a
	 * $resumed retry skips that entry and attachments already stored.
	 */
	private function appendReply(Candidate $candidate, ParsedMail $parsed, bool $automatic, string $key, bool $resumed): void {
		if (!$resumed) {
			$data = [
				'subject' => $parsed->subject,
				'body' => mb_substr($parsed->textBody, 0, self::BODY_SNIPPET_LENGTH),
				'reply' => true,
			];
			if ($automatic) {
				$data['automatic'] = true;
			}
			$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_MAIL_RECEIVED, null, $data);
			$this->seenMail->attachCandidate($key, $candidate->getId());
		}
		if ($automatic) {
			return;
		}

		$this->storeAttachments($candidate, $parsed->attachments, $resumed, true);

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
