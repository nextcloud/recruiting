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
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The basic reports dashboard (spec §4.11): funnel, time-in-stage,
 * time-to-hire, rejection reasons and applications per week — role-scoped
 * through OpeningService::listForUser.
 */
class ReportService {
	private const WEEKS = 12;

	public function __construct(
		private OpeningService $openings,
		private CandidateMapper $candidateMapper,
		private TimelineEventMapper $eventMapper,
		private OfferMapper $offerMapper,
		private ITimeFactory $timeFactory,
	) {
	}

	public function overview(string $uid): array {
		$now = \DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime());
		$openOffers = $this->offerMapper->countOpenByOpening();

		$perOpening = [];
		$allCandidates = [];
		// Interviewers are scoped to their assigned candidates, so they must
		// not see whole-opening funnels, rejection reasons or offer counts
		// (spec §2/§4.11 grant reports to managers, observers and recruiters).
		$reportRoles = [PermissionService::ROLE_RECRUITER, TeamMember::ROLE_MANAGER, TeamMember::ROLE_OBSERVER];
		foreach ($this->openings->listForUser($uid, $reportRoles) as $opening) {
			$candidates = $this->candidateMapper->findForOpening($opening['id']);
			$allCandidates = array_merge($allCandidates, $candidates);
			$events = $this->stageEventsByCandidate($candidates);

			$perOpening[] = [
				'id' => $opening['id'],
				'title' => $opening['title'],
				'status' => $opening['status'],
				'stageCounts' => $opening['stageCounts'],
				'activeCount' => $opening['activeCount'],
				'totalCount' => count($candidates),
				'avgDaysToHire' => $this->averageDaysToHire($candidates),
				'avgDaysInStage' => $this->averageDaysInStage($candidates, $events, $now),
				'rejectionReasons' => $this->rejectionReasons($candidates),
				'openOffers' => $openOffers[$opening['id']] ?? 0,
			];
		}

		return [
			'openings' => $perOpening,
			'totals' => [
				'candidates' => count($allCandidates),
				'hired' => count(array_filter($allCandidates, static fn (Candidate $c) => $c->getStage() === Candidate::STAGE_HIRED)),
				'rejected' => count(array_filter($allCandidates, static fn (Candidate $c) => $c->getStage() === Candidate::STAGE_REJECTED)),
				'avgDaysToHire' => $this->averageDaysToHire($allCandidates),
				'openOffers' => array_sum(array_map(static fn ($o) => $o['openOffers'], $perOpening)),
				'rejectionReasons' => $this->rejectionReasons($allCandidates),
			],
			'applicationsPerWeek' => $this->applicationsPerWeek($allCandidates, $now),
		];
	}

	/**
	 * Average days from application to hire, over hired candidates.
	 *
	 * @param Candidate[] $candidates
	 */
	public function averageDaysToHire(array $candidates): ?float {
		$durations = [];
		foreach ($candidates as $candidate) {
			if ($candidate->getHiredAt() !== null && $candidate->getCreatedAt() !== null) {
				$durations[] = $candidate->getHiredAt()->getTimestamp() - $candidate->getCreatedAt()->getTimestamp();
			}
		}
		if ($durations === []) {
			return null;
		}
		return round((float)array_sum($durations) / (float)(count($durations) * 86400), 1);
	}

	/**
	 * Average days spent per active stage, reconstructed from the
	 * stage_changed timeline. A candidate still sitting in a stage counts
	 * up to "now".
	 *
	 * @param Candidate[] $candidates
	 * @param array<int, TimelineEvent[]> $eventsByCandidate
	 * @return array<string, float>
	 */
	public function averageDaysInStage(array $candidates, array $eventsByCandidate, \DateTimeImmutable $now): array {
		$sums = [];
		$counts = [];
		foreach ($candidates as $candidate) {
			$cursor = $candidate->getCreatedAt();
			if ($cursor === null) {
				continue;
			}
			$stage = Candidate::STAGE_NEW;
			$periods = [];
			foreach ($eventsByCandidate[$candidate->getId()] ?? [] as $event) {
				$data = $event->getDecodedData();
				$periods[] = [$stage, $cursor, $event->getCreatedAt()];
				$stage = (string)($data['to'] ?? $stage);
				$cursor = $event->getCreatedAt();
			}
			if (!in_array($stage, Candidate::TERMINAL_STAGES, true)) {
				$periods[] = [$stage, $cursor, \DateTime::createFromImmutable($now)];
			}
			foreach ($periods as [$periodStage, $from, $to]) {
				if (!in_array($periodStage, Candidate::ACTIVE_STAGES, true)) {
					continue;
				}
				$sums[$periodStage] = ($sums[$periodStage] ?? 0) + max(0, $to->getTimestamp() - $from->getTimestamp());
				$counts[$periodStage] = ($counts[$periodStage] ?? 0) + 1;
			}
		}
		$averages = [];
		foreach (Candidate::ACTIVE_STAGES as $stage) {
			if (($counts[$stage] ?? 0) > 0) {
				$averages[$stage] = round((float)$sums[$stage] / (float)($counts[$stage] * 86400), 1);
			}
		}
		return $averages;
	}

	/**
	 * @param Candidate[] $candidates
	 * @return array<string, int>
	 */
	public function rejectionReasons(array $candidates): array {
		$reasons = [];
		foreach ($candidates as $candidate) {
			$reason = $candidate->getRejectionReason();
			if ($reason !== null && $reason !== '') {
				$reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
			}
		}
		arsort($reasons);
		return $reasons;
	}

	/**
	 * Applications per ISO week for the last 12 weeks, oldest first.
	 *
	 * @param Candidate[] $candidates
	 * @return array<int, array{week: string, count: int}>
	 */
	public function applicationsPerWeek(array $candidates, \DateTimeImmutable $now): array {
		$weeks = [];
		$start = $now->modify('monday this week');
		for ($i = self::WEEKS - 1; $i >= 0; $i--) {
			$weeks[$start->modify('-' . $i . ' weeks')->format('o-\WW')] = 0;
		}
		foreach ($candidates as $candidate) {
			$created = $candidate->getCreatedAt();
			if ($created === null) {
				continue;
			}
			$key = $created->format('o-\WW');
			if (array_key_exists($key, $weeks)) {
				$weeks[$key]++;
			}
		}
		$result = [];
		foreach ($weeks as $week => $count) {
			$result[] = ['week' => $week, 'count' => $count];
		}
		return $result;
	}

	/**
	 * @param Candidate[] $candidates
	 * @return array<int, TimelineEvent[]>
	 */
	private function stageEventsByCandidate(array $candidates): array {
		$ids = array_map(static fn (Candidate $c) => $c->getId(), $candidates);
		$byCandidate = [];
		foreach ($this->eventMapper->findByTypeFor($ids, TimelineEvent::TYPE_STAGE_CHANGED) as $event) {
			$byCandidate[$event->getCandidateId()][] = $event;
		}
		return $byCandidate;
	}
}
