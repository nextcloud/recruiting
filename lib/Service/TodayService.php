<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\VoteMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * The "Today" overview (start page and Dashboard widget): what is waiting
 * for the user across all openings. Every entry is permission-checked on
 * its own, so the overview never shows more than the candidate detail
 * would hand out.
 */
class TodayService {
	/** Interviews of the next days count as "this week" */
	public const INTERVIEW_DAYS = 7;
	/** Long lists are cut; the counts stay complete */
	public const LIST_LIMIT = 20;

	public function __construct(
		private PermissionService $permissions,
		private CandidateService $candidates,
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private TeamMemberMapper $teamMapper,
		private AssignmentMapper $assignmentMapper,
		private VoteMapper $voteMapper,
		private InterviewMapper $interviewMapper,
		private OfferMapper $offerMapper,
		private ConfigService $config,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Whether the user has any part in recruiting: recruiter, member of a
	 * hiring team, screener or offer approver.
	 */
	public function hasRecruitingRole(string $uid): bool {
		return $uid !== ''
			&& ($this->permissions->isRecruiter($uid)
				|| $this->teamMapper->findForUser($uid) !== []
				|| $this->assignmentMapper->findCandidateIdsForUser($uid) !== []
				|| $this->offerMapper->findPendingForApprover($uid) !== []);
	}

	/**
	 * @return array{
	 *     isRecruiter: bool,
	 *     openingCount: int,
	 *     votesOwed: list<array<string, mixed>>,
	 *     votesOwedCount: int,
	 *     interviews: list<array<string, mixed>>,
	 *     stuck: list<array<string, mixed>>,
	 *     stuckCount: int,
	 *     approvals: list<array<string, mixed>>,
	 *     triageCount: ?int,
	 *     mailbox: ?array{enabled: bool, status: ?array},
	 * }
	 */
	public function forUser(string $uid): array {
		$isRecruiter = $this->permissions->isRecruiter($uid);
		$titles = [];
		$votesOwed = $this->votesOwed($uid, $titles);
		$stuck = $this->candidates->stuckCandidates($uid);
		return [
			'isRecruiter' => $isRecruiter,
			'openingCount' => $isRecruiter ? count($this->openingMapper->findAll()) : count($this->teamMapper->findForUser($uid)),
			'votesOwed' => array_slice($votesOwed, 0, self::LIST_LIMIT),
			'votesOwedCount' => count($votesOwed),
			'interviews' => $this->interviews($uid, $titles),
			'stuck' => array_slice($stuck, 0, self::LIST_LIMIT),
			'stuckCount' => count($stuck),
			'approvals' => $this->approvals($uid, $titles),
			'triageCount' => $isRecruiter ? $this->candidateMapper->countTriage() : null,
			'mailbox' => $isRecruiter
				? ['enabled' => $this->config->isImapEnabled(), 'status' => $this->config->getIngestionStatus()]
				: null,
		];
	}

	/**
	 * Active candidates the user is asked to screen and has not voted on.
	 *
	 * @param array<int, ?string> $titles opening title cache
	 * @return list<array<string, mixed>>
	 */
	private function votesOwed(string $uid, array &$titles): array {
		$assigned = array_values(array_filter(
			$this->candidateMapper->findByIds($this->assignmentMapper->findCandidateIdsForUser($uid)),
			fn (Candidate $c) => !$c->isTerminal() && $this->permissions->canViewCandidate($uid, $c),
		));
		$voted = [];
		foreach ($this->voteMapper->findForUser($uid, array_map(static fn (Candidate $c) => $c->getId(), $assigned)) as $vote) {
			$voted[$vote->getCandidateId()] = true;
		}
		$result = [];
		foreach ($assigned as $candidate) {
			if (!isset($voted[$candidate->getId()])) {
				$result[] = $this->candidateEntry($candidate, $titles);
			}
		}
		return $result;
	}

	/**
	 * Confirmed interviews of the user from now until INTERVIEW_DAYS ahead.
	 *
	 * @param array<int, ?string> $titles
	 * @return list<array<string, mixed>>
	 */
	private function interviews(string $uid, array &$titles): array {
		$now = $this->timeFactory->getDateTime();
		$horizon = (clone $now)->modify('+' . self::INTERVIEW_DAYS . ' days');
		$due = array_values(array_filter(
			$this->interviewMapper->findUpcomingForUser($uid, $now),
			static fn (Interview $i) => $i->getStatus() === Interview::STATUS_CONFIRMED
				&& $i->getStartAt() !== null
				&& $i->getStartAt() <= $horizon,
		));
		$candidates = $this->candidatesById(array_map(static fn (Interview $i) => $i->getCandidateId(), $due));
		$result = [];
		foreach ($due as $interview) {
			$candidate = $candidates[$interview->getCandidateId()] ?? null;
			if ($candidate === null || !$this->permissions->canViewCandidate($uid, $candidate)) {
				continue;
			}
			$result[] = [
				'id' => $interview->getId(),
				'title' => $interview->getTitle(),
				'startAt' => $interview->getStartAt()?->format(\DateTimeInterface::ATOM),
				'endAt' => $interview->getEndAt()?->format(\DateTimeInterface::ATOM),
				'isVideo' => $interview->getIsVideo(),
				'location' => $interview->getLocation(),
				'talkLink' => $interview->getTalkLink(),
				'candidate' => $this->candidateEntry($candidate, $titles),
			];
		}
		return $result;
	}

	/**
	 * Offers waiting for the user's approval — who and for which job,
	 * never the terms.
	 *
	 * @param array<int, ?string> $titles
	 * @return list<array<string, mixed>>
	 */
	private function approvals(string $uid, array &$titles): array {
		$offers = $this->offerMapper->findPendingForApprover($uid);
		$candidates = $this->candidatesById(array_map(static fn ($o) => $o->getCandidateId(), $offers));
		$result = [];
		foreach ($offers as $offer) {
			$candidate = $candidates[$offer->getCandidateId()] ?? null;
			if ($candidate === null || !$this->permissions->canViewCandidate($uid, $candidate)) {
				continue;
			}
			$createdBy = (string)$offer->getCreatedBy();
			$result[] = [
				'offerId' => $offer->getId(),
				'jobTitle' => $offer->getJobTitle(),
				'requestedBy' => $this->userManager->getDisplayName($createdBy) ?? $createdBy,
				'candidate' => $this->candidateEntry($candidate, $titles),
			];
		}
		return $result;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, Candidate>
	 */
	private function candidatesById(array $ids): array {
		$result = [];
		foreach ($this->candidateMapper->findByIds(array_values(array_unique($ids))) as $candidate) {
			$result[$candidate->getId()] = $candidate;
		}
		return $result;
	}

	/**
	 * @param array<int, ?string> $titles
	 * @return array{id: int, displayName: string, openingId: ?int, openingTitle: ?string, stage: string}
	 */
	private function candidateEntry(Candidate $candidate, array &$titles): array {
		$openingId = $candidate->getOpeningId();
		if ($openingId !== null && !array_key_exists($openingId, $titles)) {
			$titles[$openingId] = $this->openingMapper->findTitle($openingId);
		}
		return [
			'id' => $candidate->getId(),
			'displayName' => $candidate->getDisplayName(),
			'openingId' => $openingId,
			'openingTitle' => $openingId !== null ? $titles[$openingId] : null,
			'stage' => $candidate->getStage(),
		];
	}
}
