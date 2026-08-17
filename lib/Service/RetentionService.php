<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Db\VoteMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\ICommentsManager;
use Psr\Log\LoggerInterface;

/**
 * GDPR retention automation (spec §4.8): candidates rejected or withdrawn
 * longer ago than the configured retention period are anonymized — the
 * person disappears, the statistics stay.
 */
class RetentionService {
	public const ANONYMIZED_NAME = 'Anonymized candidate';

	/** Timeline event types whose payload can identify the candidate */
	public const PII_EVENT_TYPES = [
		TimelineEvent::TYPE_MAIL_SENT,
		TimelineEvent::TYPE_MAIL_RECEIVED,
		TimelineEvent::TYPE_DOCUMENT_ADDED,
	];

	public function __construct(
		private CandidateMapper $candidateMapper,
		private VoteMapper $voteMapper,
		private TimelineEventMapper $eventMapper,
		private OfferMapper $offerMapper,
		private DocumentService $documents,
		private NotificationService $notifications,
		private ConfigService $config,
		private ICommentsManager $commentsManager,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return Candidate[] candidates whose retention period is over
	 */
	public function findEligible(): array {
		$days = $this->config->getRetentionDays();
		if ($days <= 0) {
			return [];
		}
		$cutoff = \DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime())
			->modify('-' . $days . ' days');
		return $this->candidateMapper->findRetentionEligible($cutoff);
	}

	/**
	 * Pool memberships expire on their own clock (spec §4.8).
	 *
	 * @return Candidate[]
	 */
	public function findExpiredPoolMembers(): array {
		$months = $this->config->getPoolRetentionMonths();
		$cutoff = \DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime())
			->modify('-' . $months . ' months');
		return $this->candidateMapper->findExpiredPoolMembers($cutoff);
	}

	/**
	 * @return array{anonymized: int, names: string[]}
	 */
	public function run(bool $dryRun = false): array {
		$eligible = array_merge($this->findEligible(), $this->findExpiredPoolMembers());
		$names = array_map(static fn (Candidate $c) => $c->getDisplayName(), $eligible);
		if ($dryRun) {
			return ['anonymized' => count($eligible), 'names' => $names];
		}
		foreach ($eligible as $candidate) {
			try {
				$this->anonymize($candidate);
			} catch (\Exception $e) {
				$this->logger->error('Anonymizing candidate ' . $candidate->getId() . ' failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		}
		return ['anonymized' => count($eligible), 'names' => $names];
	}

	/**
	 * Remove everything that identifies the person; keep stage, dates and
	 * vote values as anonymous aggregates for statistics.
	 */
	public function anonymize(Candidate $candidate): void {
		$id = $candidate->getId();

		$this->documents->deleteAllFor($id);
		$this->voteMapper->stripCommentsForCandidate($id);
		try {
			$this->commentsManager->deleteCommentsAtObject(CommentService::OBJECT_TYPE, (string)$id);
		} catch (\Exception) {
			// comments are best-effort
		}
		$this->notifications->purgeForCandidate($id);

		// Mail bodies, subjects and document names ("Jane_Doe_CV.pdf" identifies
		// the very person we are anonymizing) are stripped from the timeline;
		// the events themselves stay so the history remains readable.
		foreach ($this->eventMapper->findForCandidate($id) as $event) {
			if (in_array($event->getType(), self::PII_EVENT_TYPES, true)) {
				$event->setData(null);
				$this->eventMapper->update($event);
			}
		}

		// Offer notes are free text about the person; the structured terms are
		// company data and stay for the record.
		foreach ($this->offerMapper->findForCandidate($id) as $offer) {
			if (($offer->getNotes() ?? '') !== '') {
				$offer->setNotes(null);
				$this->offerMapper->update($offer);
			}
		}

		$candidate->setDisplayName(self::ANONYMIZED_NAME);
		$candidate->setEmail('');
		$candidate->setPhone('');
		$candidate->setAiSummary(null);
		$candidate->setDuplicateOf(null);
		$candidate->setPoolMember(false);
		$candidate->setPoolConsentToken(null);
		$candidate->setReplyToken(null);
		$candidate->setAnonymizedAt($this->timeFactory->getDateTime());
		$this->candidateMapper->update($candidate);

		$this->eventMapper->insert($this->anonymizedEvent($id));
	}

	private function anonymizedEvent(int $candidateId): TimelineEvent {
		$event = new TimelineEvent();
		$event->setCandidateId($candidateId);
		$event->setType(TimelineEvent::TYPE_ANONYMIZED);
		$event->setCreatedAt($this->timeFactory->getDateTime());
		return $event;
	}
}
