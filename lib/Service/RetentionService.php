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
 * GDPR retention automation (spec §4.8). Four groups, each on its own clock:
 *
 * - rejected or withdrawn longer ago than the retention period → anonymized
 * - left in an opening that was closed longer ago than the retention period,
 *   never hired → anonymized
 * - talent-pool consent expired → anonymized
 * - in the triage inbox longer than the triage period → deleted, because
 *   triage holds spam and unassigned mail, and anonymized rows there would
 *   only clutter the inbox
 *
 * Anonymizing means the person disappears and the statistics stay.
 */
class RetentionService {
	public const ANONYMIZED_NAME = 'Anonymized candidate';

	public const GROUP_ENDED = 'ended';
	public const GROUP_CLOSED_OPENING = 'closed_opening';
	public const GROUP_POOL_EXPIRED = 'pool_expired';
	public const GROUP_TRIAGE = 'triage';

	public const ACTION_ANONYMIZE = 'anonymize';
	public const ACTION_DELETE = 'delete';

	/** Timeline event types whose payload can identify the candidate */
	public const PII_EVENT_TYPES = [
		TimelineEvent::TYPE_MAIL_SENT,
		TimelineEvent::TYPE_MAIL_RECEIVED,
		TimelineEvent::TYPE_WEB_APPLICATION,
		TimelineEvent::TYPE_DOCUMENT_ADDED,
		TimelineEvent::TYPE_REFERRAL_NOTE,
		TimelineEvent::TYPE_INTERVIEW_SLOTS_DECLINED,
		TimelineEvent::TYPE_INTERVIEW_RESCHEDULE_REQUESTED,
	];

	public function __construct(
		private CandidateMapper $candidateMapper,
		private VoteMapper $voteMapper,
		private TimelineEventMapper $eventMapper,
		private OfferMapper $offerMapper,
		private DocumentService $documents,
		private NotificationService $notifications,
		private InterviewService $interviews,
		private ActivityPurgeService $activityPurge,
		private CandidateService $candidates,
		private ConfigService $config,
		private ICommentsManager $commentsManager,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return Candidate[] rejected or withdrawn candidates whose retention
	 *                     period is over
	 */
	public function findEligible(): array {
		$cutoff = $this->daysAgo($this->config->getRetentionDays());
		return $cutoff === null ? [] : $this->candidateMapper->findRetentionEligible($cutoff);
	}

	/**
	 * @return Candidate[] candidates left in openings closed longer ago than
	 *                     the retention period
	 */
	public function findLeftInClosedOpenings(): array {
		$cutoff = $this->daysAgo($this->config->getRetentionDays());
		return $cutoff === null ? [] : $this->candidateMapper->findLeftInClosedOpenings($cutoff);
	}

	/**
	 * @return Candidate[] triage candidates older than the triage period
	 */
	public function findExpiredTriage(): array {
		$cutoff = $this->daysAgo($this->config->getTriageRetentionDays());
		return $cutoff === null ? [] : $this->candidateMapper->findTriageCreatedBefore($cutoff);
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
	 * Who is due, grouped by rule. A candidate matching several rules is
	 * listed once, in the first group (triage first: a rejected triage
	 * candidate is deleted rather than anonymized).
	 *
	 * @return array<string, array{label: string, enabled: bool, action: string, reason: string, candidates: Candidate[]}>
	 */
	public function plan(): array {
		$retentionDays = $this->config->getRetentionDays();
		$triageDays = $this->config->getTriageRetentionDays();
		$groups = [
			self::GROUP_TRIAGE => [
				'label' => 'Triage inbox',
				'enabled' => $triageDays > 0,
				'action' => self::ACTION_DELETE,
				'reason' => sprintf('in the triage inbox for more than %d days', $triageDays),
				'candidates' => $this->findExpiredTriage(),
			],
			self::GROUP_ENDED => [
				'label' => 'Rejected or withdrawn',
				'enabled' => $retentionDays > 0,
				'action' => self::ACTION_ANONYMIZE,
				'reason' => sprintf('rejected or withdrawn more than %d days ago', $retentionDays),
				'candidates' => $this->findEligible(),
			],
			self::GROUP_CLOSED_OPENING => [
				'label' => 'Left in closed openings',
				'enabled' => $retentionDays > 0,
				'action' => self::ACTION_ANONYMIZE,
				'reason' => sprintf('opening closed more than %d days ago, not hired', $retentionDays),
				'candidates' => $this->findLeftInClosedOpenings(),
			],
			self::GROUP_POOL_EXPIRED => [
				'label' => 'Expired talent-pool consent',
				'enabled' => true,
				'action' => self::ACTION_ANONYMIZE,
				'reason' => sprintf('talent-pool consent older than %d months', $this->config->getPoolRetentionMonths()),
				'candidates' => $this->findExpiredPoolMembers(),
			],
		];

		$seen = [];
		foreach ($groups as $key => $group) {
			$unique = [];
			foreach ($group['candidates'] as $candidate) {
				if (!isset($seen[$candidate->getId()])) {
					$seen[$candidate->getId()] = true;
					$unique[] = $candidate;
				}
			}
			$groups[$key]['candidates'] = $unique;
		}
		return $groups;
	}

	/**
	 * @return array{anonymized: int, deleted: int, groups: array<string, array{label: string, enabled: bool, action: string, reason: string, names: string[]}>}
	 */
	public function run(bool $dryRun = false): array {
		$anonymized = 0;
		$deleted = 0;
		$report = [];
		foreach ($this->plan() as $key => $group) {
			$report[$key] = [
				'label' => $group['label'],
				'enabled' => $group['enabled'],
				'action' => $group['action'],
				'reason' => $group['reason'],
				'names' => array_map(static fn (Candidate $c) => $c->getDisplayName(), $group['candidates']),
			];
			foreach ($group['candidates'] as $candidate) {
				if ($group['action'] === self::ACTION_DELETE) {
					$deleted++;
				} else {
					$anonymized++;
				}
				if ($dryRun) {
					continue;
				}
				try {
					if ($group['action'] === self::ACTION_DELETE) {
						$this->candidates->purge($candidate);
					} else {
						$this->anonymize($candidate);
					}
				} catch (\Exception $e) {
					$this->logger->error('Retention (' . $key . ') failed for candidate ' . $candidate->getId() . ': ' . $e->getMessage(), ['exception' => $e]);
				}
			}
		}
		return ['anonymized' => $anonymized, 'deleted' => $deleted, 'groups' => $report];
	}

	private function daysAgo(int $days): ?\DateTimeImmutable {
		if ($days <= 0) {
			return null;
		}
		return \DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime())
			->modify('-' . $days . ' days');
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
		$this->activityPurge->purgeForCandidate($id);
		// Calendar events lose the name, upcoming ones are cancelled, and the
		// public interview links stop working
		$this->interviews->scrubForCandidate($candidate, revokeLinks: true);

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
		$candidate->setPortalToken(null);
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
