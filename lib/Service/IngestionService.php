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
use OCA\Recruiting\Imap\MimeParser;
use OCA\Recruiting\Imap\ParsedMail;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Fetches the dedicated applications mailbox and turns every unseen mail
 * into a candidate (spec §4.2). Plus-addressing or a [slug] subject tag
 * routes into the matching opening; everything else lands in triage.
 */
class IngestionService {
	private const MAX_MESSAGES_PER_RUN = 25;
	private const BODY_SNIPPET_LENGTH = 20_000;

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
	) {
	}

	/**
	 * @return array{fetched: int, created: int}
	 * @throws ImapException when the mailbox is unreachable
	 */
	public function fetchNow(): array {
		if (!$this->config->isImapEnabled()) {
			return ['fetched' => 0, 'created' => 0];
		}
		$imap = $this->config->getImapConfig();
		if ($imap['host'] === '' || $imap['user'] === '') {
			return ['fetched' => 0, 'created' => 0];
		}

		try {
			$result = $this->fetchFromMailbox($imap);
		} catch (\Exception $e) {
			// Recorded so the triage view can warn — a silently failing
			// mailbox is lost applications
			$this->recordRun(0, 0, $e->getMessage());
			throw $e;
		}
		$this->recordRun($result['fetched'], $result['created'], null);
		return $result;
	}

	/**
	 * @param array{host: string, port: int, security: string, user: string, password: string} $imap
	 * @return array{fetched: int, created: int}
	 */
	private function fetchFromMailbox(array $imap): array {
		$client = $this->createClient($imap['host'], $imap['port'], $imap['security']);
		$client->connect();
		try {
			$client->login($imap['user'], $imap['password']);
			$client->selectInbox();

			$uids = array_slice($client->searchUnseen(), 0, self::MAX_MESSAGES_PER_RUN);
			$created = 0;
			foreach ($uids as $uid) {
				$raw = $client->fetchMessage($uid);
				try {
					$this->processMessage($raw);
					$created++;
				} catch (\Exception $e) {
					// A poison message must not block the mailbox forever:
					// log it, mark it seen and move on.
					$this->logger->error('Failed to ingest application mail (UID ' . $uid . '): ' . $e->getMessage(), ['exception' => $e]);
				}
				$client->markSeen($uid);
			}
			return ['fetched' => count($uids), 'created' => $created];
		} finally {
			$client->logout();
		}
	}

	private function recordRun(int $fetched, int $created, ?string $error): void {
		$this->config->setIngestionStatus([
			'ranAt' => $this->timeFactory->getDateTime()->format(\DateTimeInterface::ATOM),
			'fetched' => $fetched,
			'created' => $created,
			'error' => $error,
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

	private function processMessage(string $raw): void {
		$parsed = (new MimeParser())->parse($raw);

		// Idempotency: a crash between processing and the IMAP seen-flag would
		// re-deliver this mail on the next run. The Message-ID ledger is
		// written after successful processing (at-least-once, never lost).
		if ($this->seenMail->hasSeen($parsed->messageId)) {
			$this->logger->info('Skipping already-ingested mail ' . $parsed->messageId);
			return;
		}

		// A reply to one of our own mails (per-candidate Reply-To) belongs on
		// the existing candidate's timeline, not in triage as a "new" application.
		$existing = $this->matchReply($parsed);
		if ($existing !== null) {
			$this->appendReply($existing, $parsed);
			$this->seenMail->remember($parsed->messageId);
			return;
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

		if ($opening !== null && $opening->getAutoAck() && $candidate->getEmail() !== '') {
			try {
				$draft = $this->mail->preview($candidate, $opening->getTitle(), MailTemplate::TYPE_RECEIPT, null, null);
				$this->mail->sendToCandidate($candidate, $draft['subject'], $draft['body'], null);
			} catch (\Exception $e) {
				$this->logger->warning('Auto-acknowledgement failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		}

		$this->seenMail->remember($parsed->messageId);
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
	 * and tell the hiring managers.
	 */
	private function appendReply(Candidate $candidate, ParsedMail $parsed): void {
		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_MAIL_RECEIVED, null, [
			'subject' => $parsed->subject,
			'body' => mb_substr($parsed->textBody, 0, self::BODY_SNIPPET_LENGTH),
			'reply' => true,
		]);

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
