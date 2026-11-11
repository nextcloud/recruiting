<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewAttendee;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\InterviewFeedback;
use OCA\Recruiting\Db\InterviewFeedbackMapper;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\Vote;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Feedback after an interview round: every interviewer gives a verdict
 * (the screening vote values) and notes. Others' feedback stays hidden
 * until you gave your own — the anti-anchoring rule of the votes.
 */
class InterviewFeedbackService {
	/** How far back "My interviews" lists rounds still waiting for your feedback */
	public const OWED_DAYS = 30;

	public function __construct(
		private InterviewFeedbackMapper $feedbackMapper,
		private InterviewMapper $interviewMapper,
		private InterviewAttendeeMapper $attendeeMapper,
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private TeamMemberMapper $teamMapper,
		private PermissionService $permissions,
		private TimelineService $timeline,
		private NotificationService $notifications,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * A round is over once it is done, or confirmed and past its end (the
	 * hourly job marks those done a little later).
	 */
	public function isOver(Interview $interview): bool {
		if ($interview->getStatus() === Interview::STATUS_DONE) {
			return true;
		}
		$endAt = $interview->getEndAt();
		return $interview->getStatus() === Interview::STATUS_CONFIRMED
			&& $endAt !== null
			&& $endAt <= $this->timeFactory->getDateTime();
	}

	/**
	 * Give or update the caller's feedback on a round. Only the round's
	 * interviewers may, once it is over; a "no" needs notes. When the last
	 * interviewer has answered, the opening's managers and the organizer
	 * are notified.
	 *
	 * @return array the round's feedback as summaries() shows it to the caller
	 * @throws NotPermittedException|ValidationException
	 */
	public function submit(string $uid, int $interviewId, string $verdict, string $notes): array {
		$interview = $this->interviewMapper->find($interviewId);
		$candidate = $this->candidateMapper->find($interview->getCandidateId());
		$this->permissions->assertCanViewCandidate($uid, $candidate);
		$attendeeUids = $this->attendeeUids($interview);
		if (!in_array($uid, $attendeeUids, true)) {
			throw new NotPermittedException($this->l10n->t('Only the interviewers of this round can give feedback'));
		}
		if (!$this->isOver($interview)) {
			throw new ValidationException($this->l10n->t('Feedback opens once the interview has taken place'));
		}
		if ($candidate->getAnonymizedAt() !== null) {
			throw new ValidationException($this->l10n->t('This candidate has been anonymized'));
		}
		if (!in_array($verdict, Vote::VALUES, true)) {
			throw new ValidationException($this->l10n->t('Invalid verdict'));
		}
		$notes = trim($notes);
		if ($verdict === Vote::NO && $notes === '') {
			throw new ValidationException($this->l10n->t('Please explain why your verdict is no'));
		}

		$feedback = $this->feedbackMapper->findFeedback($interviewId, $uid);
		$isNew = $feedback === null;
		if ($feedback === null) {
			$feedback = new InterviewFeedback();
			$feedback->setInterviewId($interviewId);
			$feedback->setUid($uid);
		}
		$feedback->setVerdict($verdict);
		$feedback->setNotes($notes !== '' ? $notes : null);
		$feedback->setUpdatedAt($this->timeFactory->getDateTime());
		if ($isNew) {
			$this->feedbackMapper->insert($feedback);
		} else {
			$this->feedbackMapper->update($feedback);
		}

		if ($isNew) {
			// Who gave feedback, never what: the verdict stays behind the rule
			$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_INTERVIEW_FEEDBACK, $uid, [
				'title' => $interview->getTitle(),
			]);
			$given = array_map(
				static fn (InterviewFeedback $f) => $f->getUid(),
				$this->feedbackMapper->findForInterviews([$interviewId]),
			);
			if (array_diff($attendeeUids, $given) === []) {
				$this->notifications->notify(
					array_merge($this->managerUids($candidate), [$interview->getCreatedBy()]),
					NotificationService::SUBJECT_INTERVIEW_FEEDBACK_COMPLETE,
					$candidate,
					$uid,
					['interviewTitle' => $interview->getTitle()],
				);
			}
		}
		return $this->summaries($uid, $candidate, [$interview])[$interviewId];
	}

	/**
	 * Feedback per round for the candidate detail, filtered by the
	 * anti-anchoring rule per round: until you gave feedback on a round,
	 * you see who answered, not what.
	 *
	 * @param Interview[] $interviews
	 * @return array<int, array{feedbackOpen: bool, canGiveFeedback: bool, myFeedback: ?array, feedback: list<array>, attendeeCount: int}>
	 */
	public function summaries(string $uid, Candidate $candidate, array $interviews): array {
		$byInterview = [];
		foreach ($this->feedbackMapper->findForInterviews(array_map(static fn (Interview $i) => $i->getId(), $interviews)) as $feedback) {
			$byInterview[$feedback->getInterviewId()][] = $feedback;
		}
		$summaries = [];
		foreach ($interviews as $interview) {
			$rows = $byInterview[$interview->getId()] ?? [];
			$mine = null;
			foreach ($rows as $row) {
				if ($row->getUid() === $uid) {
					$mine = $row;
				}
			}
			$canSee = $this->permissions->maySeeVerdicts($uid, $candidate, $mine !== null);
			$attendeeUids = $this->attendeeUids($interview);
			$open = $this->isOver($interview);
			$summaries[$interview->getId()] = [
				'feedbackOpen' => $open,
				'canGiveFeedback' => $open && $candidate->getAnonymizedAt() === null && in_array($uid, $attendeeUids, true),
				'myFeedback' => $mine?->jsonSerialize(),
				'feedback' => array_map(function (InterviewFeedback $row) use ($uid, $canSee) {
					$data = $row->jsonSerialize();
					$data['displayName'] = $this->userManager->getDisplayName($row->getUid()) ?? $row->getUid();
					if (!$canSee && $row->getUid() !== $uid) {
						$data['verdict'] = null;
						$data['notes'] = null;
					}
					return $data;
				}, $rows),
				'attendeeCount' => count($attendeeUids),
			];
		}
		return $summaries;
	}

	/**
	 * Rounds of the last OWED_DAYS days the user sat on and has not given
	 * feedback on yet ("My interviews").
	 *
	 * @return list<array>
	 */
	public function owed(string $uid): array {
		$now = $this->timeFactory->getDateTime();
		$since = (clone $now)->modify('-' . self::OWED_DAYS . ' days');
		$result = [];
		foreach ($this->interviewMapper->findFeedbackOwed($uid, $since, $now) as $interview) {
			try {
				$candidate = $this->candidateMapper->find($interview->getCandidateId());
			} catch (DoesNotExistException) {
				continue;
			}
			if ($candidate->getAnonymizedAt() !== null || !$this->permissions->canViewCandidate($uid, $candidate)) {
				continue;
			}
			$entry = $interview->jsonSerialize();
			$entry['candidate'] = [
				'id' => $candidate->getId(),
				'displayName' => $candidate->getDisplayName(),
				'openingId' => $candidate->getOpeningId(),
				'openingTitle' => $this->openingMapper->findTitle($candidate->getOpeningId()),
			];
			$result[] = $entry;
		}
		return $result;
	}

	/**
	 * GDPR hard delete.
	 */
	public function deleteForCandidate(int $candidateId): void {
		$this->feedbackMapper->deleteForCandidate($candidateId);
	}

	/**
	 * Anonymization: the verdicts stay for statistics, the notes go.
	 */
	public function stripNotesForCandidate(int $candidateId): void {
		$this->feedbackMapper->stripNotesForCandidate($candidateId);
	}

	/**
	 * @return string[]
	 */
	private function attendeeUids(Interview $interview): array {
		return array_map(
			static fn (InterviewAttendee $a) => $a->getUid(),
			$this->attendeeMapper->findForInterview($interview->getId()),
		);
	}

	/**
	 * @return string[]
	 */
	private function managerUids(Candidate $candidate): array {
		$openingId = $candidate->getOpeningId();
		if ($openingId === null) {
			return [];
		}
		$managers = [];
		foreach ($this->teamMapper->findForOpening($openingId) as $member) {
			if ($member->getRole() === TeamMember::ROLE_MANAGER) {
				$managers[] = $member->getUid();
			}
		}
		return $managers;
	}
}
