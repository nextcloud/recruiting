<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Assignment;
use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\Vote;
use OCA\Recruiting\Db\VoteMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

class ScreeningService {
	public function __construct(
		private CandidateMapper $candidateMapper,
		private AssignmentMapper $assignmentMapper,
		private VoteMapper $voteMapper,
		private PermissionService $permissions,
		private TimelineService $timeline,
		private NotificationService $notifications,
		private ActivityPublisher $activity,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * Assign screeners to a candidate. Assignees must have a (non-observer)
	 * role for the opening — otherwise they could not even see the candidate.
	 *
	 * @param string[] $uids
	 * @throws NotPermittedException|ValidationException
	 */
	public function assign(string $actorUid, int $candidateId, array $uids): void {
		$candidate = $this->candidateMapper->find($candidateId);
		$this->permissions->assertCanManageCandidate($actorUid, $candidate);
		$openingId = $candidate->getOpeningId();
		if ($openingId === null) {
			throw new ValidationException($this->l10n->t('Assign the candidate to an opening first'));
		}

		foreach (array_unique(array_filter(array_map('strval', $uids))) as $uid) {
			if ($this->assignmentMapper->findAssignment($candidateId, $uid) !== null) {
				continue;
			}
			$role = $this->permissions->roleForOpening($uid, $openingId);
			if ($role === null || $role === TeamMember::ROLE_OBSERVER) {
				throw new ValidationException($this->l10n->t('%s is not part of this opening\'s hiring team', [$uid]));
			}

			$assignment = new Assignment();
			$assignment->setCandidateId($candidateId);
			$assignment->setUid($uid);
			$assignment->setAssignedBy($actorUid);
			$assignment->setCreatedAt($this->timeFactory->getDateTime());
			$this->assignmentMapper->insert($assignment);

			$this->timeline->record($candidateId, TimelineEvent::TYPE_SCREENER_ASSIGNED, $actorUid, ['screener' => $uid]);
			$this->notifications->notify([$uid], NotificationService::SUBJECT_SCREENER_ASSIGNED, $candidate, $actorUid);
		}
	}

	/**
	 * @throws NotPermittedException
	 */
	public function unassign(string $actorUid, int $candidateId, string $uid): void {
		$candidate = $this->candidateMapper->find($candidateId);
		$this->permissions->assertCanManageCandidate($actorUid, $candidate);

		$assignment = $this->assignmentMapper->findAssignment($candidateId, $uid);
		if ($assignment === null) {
			return;
		}
		$this->assignmentMapper->delete($assignment);
		$this->timeline->record($candidateId, TimelineEvent::TYPE_SCREENER_REMOVED, $actorUid, ['screener' => $uid]);
	}

	/**
	 * Cast or update the caller's vote: yes / maybe / no plus a comment.
	 * A "no" without a word of explanation is not allowed (spec §4.3).
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function vote(string $uid, int $candidateId, string $value, string $comment): Vote {
		$candidate = $this->candidateMapper->find($candidateId);
		if (!$this->permissions->canVote($uid, $candidate)) {
			throw new NotPermittedException('Not allowed to vote on this candidate');
		}
		if (!in_array($value, Vote::VALUES, true)) {
			throw new ValidationException($this->l10n->t('Invalid vote'));
		}
		$comment = trim($comment);
		if ($value === Vote::NO && $comment === '') {
			throw new ValidationException($this->l10n->t('Please explain why you are voting no'));
		}

		$now = $this->timeFactory->getDateTime();
		$vote = $this->voteMapper->findVote($candidateId, $uid);
		$isNew = $vote === null;
		if ($vote === null) {
			$vote = new Vote();
			$vote->setCandidateId($candidateId);
			$vote->setUid($uid);
			$vote->setCreatedAt($now);
		}
		$vote->setVote($value);
		$vote->setComment($comment);
		$vote->setUpdatedAt($now);
		$vote = $isNew ? $this->voteMapper->insert($vote) : $this->voteMapper->update($vote);

		if ($isNew) {
			// The vote value itself stays out of timeline and activity so the
			// hidden-until-you-vote rule cannot be bypassed there.
			$this->timeline->record($candidateId, TimelineEvent::TYPE_VOTE_CAST, $uid);
			$this->activity->publish($candidate, 'vote_cast', $uid);
		}
		return $vote;
	}
}
