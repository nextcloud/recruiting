<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCP\IGroupManager;

/**
 * The single authority for every permission decision in the app.
 *
 * Roles (spec §2):
 *  - recruiter: member of the admin-configured HR group — full access
 *  - manager:   per-opening — full access to their opening's candidates
 *  - interviewer: per-opening — only candidates assigned to them
 *  - observer:  per-opening — read only
 */
class PermissionService {
	public const ROLE_RECRUITER = 'recruiter';

	/** @var array<string, bool> */
	private array $recruiterCache = [];

	/**
	 * Per-request cache of "which role does this user hold in this opening".
	 * Board rendering asks this once per candidate, so without it a large
	 * board would issue one membership query per card.
	 *
	 * @var array<string, ?string> "uid\0openingId" => role|null
	 */
	private array $roleCache = [];

	public function __construct(
		private IGroupManager $groupManager,
		private ConfigService $config,
		private TeamMemberMapper $teamMapper,
		private AssignmentMapper $assignmentMapper,
		private InterviewAttendeeMapper $attendeeMapper,
		private OfferMapper $offerMapper,
	) {
	}

	public function isRecruiter(string $uid): bool {
		if (!isset($this->recruiterCache[$uid])) {
			$this->recruiterCache[$uid] = $this->groupManager->isInGroup($uid, $this->config->getHrGroup());
		}
		return $this->recruiterCache[$uid];
	}

	/**
	 * The user's effective role for an opening, or null when they have none.
	 */
	public function roleForOpening(string $uid, int $openingId): ?string {
		if ($this->isRecruiter($uid)) {
			return self::ROLE_RECRUITER;
		}
		$key = $uid . "\0" . $openingId;
		if (!array_key_exists($key, $this->roleCache)) {
			$this->roleCache[$key] = $this->teamMapper->findMembership($openingId, $uid)?->getRole();
		}
		return $this->roleCache[$key];
	}

	/**
	 * Forget cached roles — call after changing a hiring team within the
	 * same request, so subsequent checks see the new membership.
	 */
	public function clearRoleCache(): void {
		$this->roleCache = [];
	}

	public function canViewOpening(string $uid, int $openingId): bool {
		return $this->roleForOpening($uid, $openingId) !== null;
	}

	/**
	 * Manage = edit the opening, create/move candidates, assign screeners,
	 * schedule interviews, send mail.
	 */
	public function canManageOpening(string $uid, int $openingId): bool {
		$role = $this->roleForOpening($uid, $openingId);
		return $role === self::ROLE_RECRUITER || $role === TeamMember::ROLE_MANAGER;
	}

	public function canViewCandidate(string $uid, Candidate $candidate): bool {
		$openingId = $candidate->getOpeningId();
		if ($openingId === null) {
			// Triage inbox is recruiter-only
			return $this->isRecruiter($uid);
		}
		$role = $this->roleForOpening($uid, $openingId);
		if ($role === TeamMember::ROLE_INTERVIEWER) {
			// Interviewers only see candidates assigned to them …
			$candidateId = $candidate->getId();
			if ($this->assignmentMapper->findAssignment($candidateId, $uid) !== null
				|| in_array($candidateId, $this->attendeeMapper->findCandidateIdsForUser($uid), true)) {
				return true;
			}
		} elseif ($role !== null) {
			return true;
		}
		// … but being asked to approve an offer always grants access to that
		// candidate — whatever role (or none) the approver holds otherwise.
		return $this->isOfferApprover($uid, $candidate);
	}

	/**
	 * Offer approvers get scoped read access to exactly the candidate whose
	 * offer they are asked to approve (spec §4.6) — checked separately so the
	 * interviewer-scoping above stays untouched.
	 */
	public function isOfferApprover(string $uid, Candidate $candidate): bool {
		return $this->offerMapper->isApproverForCandidate($candidate->getId(), $uid);
	}

	/**
	 * Offer data (salary!) is visible only to recruiters, the opening's
	 * hiring managers and the offer's approver.
	 */
	public function canSeeOffers(string $uid, Candidate $candidate): bool {
		return $this->canManageCandidate($uid, $candidate) || $this->isOfferApprover($uid, $candidate);
	}

	public function canManageCandidate(string $uid, Candidate $candidate): bool {
		$openingId = $candidate->getOpeningId();
		if ($openingId === null) {
			return $this->isRecruiter($uid);
		}
		return $this->canManageOpening($uid, $openingId);
	}

	/**
	 * Voting: managers, recruiters and assigned screeners — never observers.
	 */
	public function canVote(string $uid, Candidate $candidate): bool {
		if ($this->canManageCandidate($uid, $candidate)) {
			return true;
		}
		return $this->assignmentMapper->findAssignment($candidate->getId(), $uid) !== null;
	}

	/**
	 * Commenting: everyone who can view except read-only observers.
	 */
	public function canComment(string $uid, Candidate $candidate): bool {
		if (!$this->canViewCandidate($uid, $candidate)) {
			return false;
		}
		$openingId = $candidate->getOpeningId();
		if ($openingId === null) {
			return $this->isRecruiter($uid);
		}
		return $this->roleForOpening($uid, $openingId) !== TeamMember::ROLE_OBSERVER;
	}

	/**
	 * @throws NotPermittedException
	 */
	public function assertRecruiter(string $uid): void {
		if (!$this->isRecruiter($uid)) {
			throw new NotPermittedException('Recruiter access required');
		}
	}

	/**
	 * @throws NotPermittedException
	 */
	public function assertCanViewOpening(string $uid, int $openingId): void {
		if (!$this->canViewOpening($uid, $openingId)) {
			throw new NotPermittedException('No access to this opening');
		}
	}

	/**
	 * @throws NotPermittedException
	 */
	public function assertCanManageOpening(string $uid, int $openingId): void {
		if (!$this->canManageOpening($uid, $openingId)) {
			throw new NotPermittedException('No management access to this opening');
		}
	}

	/**
	 * @throws NotPermittedException
	 */
	public function assertCanViewCandidate(string $uid, Candidate $candidate): void {
		if (!$this->canViewCandidate($uid, $candidate)) {
			throw new NotPermittedException('No access to this candidate');
		}
	}

	/**
	 * @throws NotPermittedException
	 */
	public function assertCanManageCandidate(string $uid, Candidate $candidate): void {
		if (!$this->canManageCandidate($uid, $candidate)) {
			throw new NotPermittedException('No management access to this candidate');
		}
	}
}
