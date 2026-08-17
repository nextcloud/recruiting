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
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\InterviewSlot;
use OCA\Recruiting\Db\InterviewSlotMapper;
use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Interview lifecycle: propose slots → invite the candidate → the candidate
 * confirms on the public page → calendar event + Talk room (spec §4.4).
 */
class InterviewService {
	public function __construct(
		private InterviewMapper $interviewMapper,
		private InterviewSlotMapper $slotMapper,
		private InterviewAttendeeMapper $attendeeMapper,
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private PermissionService $permissions,
		private SlotFinderService $slotFinder,
		private CalendarService $calendar,
		private TalkService $talk,
		private MailService $mail,
		private StageService $stages,
		private TimelineService $timeline,
		private NotificationService $notifications,
		private ActivityPublisher $activity,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * Free/busy based slot proposals for the scheduling dialog.
	 *
	 * @param string[] $attendeeUids
	 * @throws NotPermittedException
	 */
	public function proposeSlots(string $uid, int $candidateId, array $attendeeUids, int $durationMin, string $timezone): array {
		$candidate = $this->candidateMapper->find($candidateId);
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		$organizer = $this->userManager->get($uid);
		if ($organizer === null) {
			throw new NotPermittedException('No session user');
		}
		return $this->slotFinder->propose($organizer, $attendeeUids, $durationMin, $timezone);
	}

	/**
	 * Create the interview with its proposed slots and return it together
	 * with the prefilled invite mail draft.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function create(string $uid, int $candidateId, array $data): array {
		$candidate = $this->candidateMapper->find($candidateId);
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		$openingId = $candidate->getOpeningId();
		if ($openingId === null) {
			throw new ValidationException($this->l10n->t('Assign the candidate to an opening first'));
		}

		$title = trim((string)($data['title'] ?? '')) ?: $this->l10n->t('Interview');
		$durationMin = max(15, min(480, (int)($data['durationMin'] ?? 60)));
		$isVideo = (bool)($data['isVideo'] ?? false);
		$location = trim((string)($data['location'] ?? ''));
		if ($isVideo && !$this->talk->isAvailable()) {
			$isVideo = false;
		}

		$attendeeUids = array_values(array_unique(array_filter(array_map('strval', $data['attendees'] ?? []))));
		if ($attendeeUids === []) {
			throw new ValidationException($this->l10n->t('Pick at least one interviewer'));
		}
		foreach ($attendeeUids as $attendeeUid) {
			if ($this->userManager->get($attendeeUid) === null) {
				throw new ValidationException($this->l10n->t('Unknown user: %s', [$attendeeUid]));
			}
			if ($this->permissions->roleForOpening($attendeeUid, $openingId) === null) {
				throw new ValidationException($this->l10n->t('%s is not part of this opening\'s hiring team', [$attendeeUid]));
			}
		}

		$slots = $this->parseSlots($data['slots'] ?? [], $durationMin);

		$interview = new Interview();
		$interview->setCandidateId($candidateId);
		$interview->setTitle($title);
		$interview->setStatus(Interview::STATUS_PROPOSED);
		$interview->setDurationMin($durationMin);
		$interview->setIsVideo($isVideo);
		$interview->setLocation($location);
		$interview->setPublicToken($this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC));
		$interview->setCreatedBy($uid);
		$interview->setCreatedAt($this->timeFactory->getDateTime());
		$interview = $this->interviewMapper->insert($interview);

		foreach ($slots as [$start, $end]) {
			$slot = new InterviewSlot();
			$slot->setInterviewId($interview->getId());
			$slot->setStartAt($start);
			$slot->setEndAt($end);
			$this->slotMapper->insert($slot);
		}
		foreach ($attendeeUids as $attendeeUid) {
			$attendee = new InterviewAttendee();
			$attendee->setInterviewId($interview->getId());
			$attendee->setUid($attendeeUid);
			$this->attendeeMapper->insert($attendee);
		}

		$this->timeline->record($candidateId, TimelineEvent::TYPE_INTERVIEW_PROPOSED, $uid, [
			'title' => $title,
			'slotCount' => count($slots),
		]);
		$this->activity->publish($candidate, 'interview_scheduled', $uid, ['title' => $title]);
		$this->notifications->notify($attendeeUids, NotificationService::SUBJECT_INTERVIEW_ASSIGNED, $candidate, $uid, [
			'interviewTitle' => $title,
		]);

		$openingTitle = $this->openingMapper->findTitle($candidate->getOpeningId());
		$inviteDraft = $this->mail->preview($candidate, $openingTitle, MailTemplate::TYPE_INTERVIEW_INVITE, null, $uid, [
			'interview_title' => $title,
			'interview_link' => $this->publicUrl($interview),
		]);

		return [
			'interview' => $this->serialize($interview),
			'inviteDraft' => $inviteDraft,
		];
	}

	/**
	 * Send the invite mail. The public link is what makes self-scheduling
	 * work, so if the edited mail lost it we append it again.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function sendInvite(string $uid, int $interviewId, string $subject, string $body): array {
		$interview = $this->interviewMapper->find($interviewId);
		$candidate = $this->candidateMapper->find($interview->getCandidateId());
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		if ($interview->getStatus() !== Interview::STATUS_PROPOSED) {
			throw new ValidationException($this->l10n->t('This interview is no longer awaiting confirmation'));
		}

		$url = $this->publicUrl($interview);
		if (!str_contains($body, $url)) {
			$body = rtrim($body) . "\n\n" . $this->l10n->t('Pick your interview time here: %s', [$url]);
		}
		$this->mail->sendToCandidate($candidate, $subject, $body, $uid);
		return $this->serialize($this->interviewMapper->find($interviewId));
	}

	/**
	 * Candidate picked a slot on the public page (no login).
	 *
	 * @throws ValidationException
	 */
	public function confirmSlot(Interview $interview, int $slotId): void {
		if ($interview->getStatus() !== Interview::STATUS_PROPOSED) {
			throw new ValidationException($this->l10n->t('This interview is no longer awaiting confirmation'));
		}
		$slot = $this->slotMapper->find($slotId);
		if ($slot->getInterviewId() !== $interview->getId()) {
			throw new ValidationException($this->l10n->t('Invalid slot'));
		}
		if ($slot->getStartAt() <= $this->timeFactory->getDateTime()) {
			throw new ValidationException($this->l10n->t('This slot is in the past — please pick another one'));
		}
		// Take the interview out of "proposed" atomically: a second, parallel
		// submission of the public page must not book a second calendar event.
		if (!$this->interviewMapper->claimForConfirmation($interview->getId())) {
			throw new ValidationException($this->l10n->t('This interview is no longer awaiting confirmation'));
		}
		$candidate = $this->candidateMapper->find($interview->getCandidateId());

		$slot->setChosen(true);
		$this->slotMapper->update($slot);

		$interview->setStartAt($slot->getStartAt());
		$interview->setEndAt($slot->getEndAt());
		$interview->setStatus(Interview::STATUS_CONFIRMED);

		$attendees = $this->attendeeMapper->findForInterview($interview->getId());
		$attendeeUids = array_map(static fn (InterviewAttendee $a) => $a->getUid(), $attendees);

		// Talk room for video interviews
		if ($interview->getIsVideo()) {
			$moderators = [];
			foreach (array_unique(array_merge([$interview->getCreatedBy()], $attendeeUids)) as $moderatorUid) {
				$moderator = $this->userManager->get($moderatorUid);
				if ($moderator !== null) {
					$moderators[] = $moderator;
				}
			}
			$room = $this->talk->createRoom(
				$this->l10n->t('Interview: %1$s (%2$s)', [$candidate->getDisplayName(), $interview->getTitle()]),
				$moderators,
			);
			if ($room !== null) {
				$interview->setTalkToken($room['token']);
				$interview->setTalkLink($room['url']);
			}
		}

		// Calendar event in the organizer's calendar, interviewers as iTIP attendees
		$organizer = $this->userManager->get($interview->getCreatedBy());
		if ($organizer !== null) {
			$emails = $this->attendeeEmails($attendeeUids);
			$description = $this->l10n->t('Candidate: %s', [$candidate->getDisplayName()]);
			$openingTitle = $this->openingMapper->findTitle($candidate->getOpeningId());
			if ($openingTitle !== null) {
				$description .= "\n" . $this->l10n->t('Opening: %s', [$openingTitle]);
			}
			if ($interview->getTalkLink() !== '') {
				$description .= "\n" . $interview->getTalkLink();
			}
			$fileName = $this->calendar->createEvent(
				$organizer,
				$interview->getTitle() . ' — ' . $candidate->getDisplayName(),
				$description,
				$slot->getStartAt(),
				$slot->getEndAt(),
				$interview->getTalkLink() !== '' ? $interview->getTalkLink() : $interview->getLocation(),
				$emails,
			);
			if ($fileName !== null) {
				$interview->setCalendarUid($fileName);
			}
		}

		$this->interviewMapper->update($interview);

		// The pipeline follows reality: a confirmed interview moves the card
		if (in_array($candidate->getStage(), [Candidate::STAGE_NEW, Candidate::STAGE_SCREENING], true)) {
			$this->stages->apply($candidate, Candidate::STAGE_INTERVIEW, null);
		}

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_INTERVIEW_CONFIRMED, null, [
			'title' => $interview->getTitle(),
			'startAt' => $slot->getStartAt()->format(\DateTimeInterface::ATOM),
		]);
		$this->activity->publish($candidate, 'interview_confirmed', null, ['title' => $interview->getTitle()]);
		$this->notifications->notify(
			array_unique(array_merge($attendeeUids, [$interview->getCreatedBy()])),
			NotificationService::SUBJECT_INTERVIEW_CONFIRMED,
			$candidate,
			null,
			['interviewTitle' => $interview->getTitle(), 'startAt' => $slot->getStartAt()->format(\DateTimeInterface::ATOM)],
		);
	}

	/**
	 * @throws NotPermittedException
	 */
	public function cancel(string $uid, int $interviewId): array {
		$interview = $this->interviewMapper->find($interviewId);
		$candidate = $this->candidateMapper->find($interview->getCandidateId());
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		$this->doCancel($interview, $candidate, $uid, notify: true);
		return $this->serialize($this->interviewMapper->find($interviewId));
	}

	/**
	 * Remove every interview of a candidate including its proposed slots and
	 * attendee rows — used by the GDPR hard delete, where leftovers would
	 * defeat the whole point.
	 */
	public function deleteAllFor(int $candidateId): void {
		foreach ($this->interviewMapper->findForCandidate($candidateId) as $interview) {
			$this->slotMapper->deleteForInterview($interview->getId());
			$this->attendeeMapper->deleteForInterview($interview->getId());
		}
		$this->interviewMapper->deleteForCandidate($candidateId);
	}

	/**
	 * Cancel every open interview of a candidate (used on deletion).
	 */
	public function cancelAllFor(Candidate $candidate, string $actorUid, bool $notify): void {
		foreach ($this->interviewMapper->findForCandidate($candidate->getId()) as $interview) {
			if (in_array($interview->getStatus(), [Interview::STATUS_PROPOSED, Interview::STATUS_CONFIRMED], true)) {
				$this->doCancel($interview, $candidate, $actorUid, $notify);
			}
		}
	}

	private function doCancel(Interview $interview, Candidate $candidate, string $actorUid, bool $notify): void {
		if ($interview->getStatus() === Interview::STATUS_CANCELLED) {
			return;
		}
		$attendees = $this->attendeeMapper->findForInterview($interview->getId());
		$attendeeUids = array_map(static fn (InterviewAttendee $a) => $a->getUid(), $attendees);

		if ($interview->getCalendarUid() !== '') {
			$organizer = $this->userManager->get($interview->getCreatedBy());
			if ($organizer !== null) {
				// Deleting the object makes CalDAV send the CANCEL to attendees
				$this->calendar->cancelEvent($organizer, $interview->getCalendarUid());
			}
		}
		if ($interview->getTalkToken() !== '') {
			$this->talk->deleteRoom($interview->getTalkToken());
		}

		$interview->setStatus(Interview::STATUS_CANCELLED);
		$interview->setTalkLink('');
		$interview->setTalkToken('');
		$this->interviewMapper->update($interview);

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_INTERVIEW_CANCELLED, $actorUid, [
			'title' => $interview->getTitle(),
		]);
		if ($notify) {
			$this->activity->publish($candidate, 'interview_cancelled', $actorUid, ['title' => $interview->getTitle()]);
			$this->notifications->notify($attendeeUids, NotificationService::SUBJECT_INTERVIEW_CANCELLED, $candidate, $actorUid, [
				'interviewTitle' => $interview->getTitle(),
			]);
		}
	}

	/**
	 * Everything the public page needs — and nothing more (no internal
	 * notes, no other candidates, no interviewer identities).
	 */
	public function publicData(Interview $interview): array {
		$candidate = $this->candidateMapper->find($interview->getCandidateId());
		$now = $this->timeFactory->getDateTime();
		$slots = array_values(array_filter(
			$this->slotMapper->findForInterview($interview->getId()),
			static fn (InterviewSlot $slot) => $slot->getStartAt() > $now,
		));
		return [
			'status' => $interview->getStatus(),
			'title' => $interview->getTitle(),
			'candidateName' => $candidate->getDisplayName(),
			'openingTitle' => $this->openingMapper->findTitle($candidate->getOpeningId()),
			'durationMin' => $interview->getDurationMin(),
			'isVideo' => $interview->getIsVideo(),
			'location' => $interview->getLocation(),
			'talkLink' => $interview->getStatus() === Interview::STATUS_CONFIRMED ? $interview->getTalkLink() : '',
			'startAt' => $interview->getStartAt()?->format(\DateTimeInterface::ATOM),
			'endAt' => $interview->getEndAt()?->format(\DateTimeInterface::ATOM),
			'slots' => array_map(static fn (InterviewSlot $slot) => $slot->jsonSerialize(), $slots),
		];
	}

	public function serialize(Interview $interview): array {
		$data = $interview->jsonSerialize();
		$data['attendees'] = $this->attendeesFor($interview);
		$data['slots'] = array_map(
			static fn (InterviewSlot $slot) => $slot->jsonSerialize(),
			$this->slotMapper->findForInterview($interview->getId()),
		);
		$data['publicUrl'] = $this->publicUrl($interview);
		return $data;
	}

	/**
	 * Interviewers that can actually be invited by iTIP.
	 *
	 * @param string[] $attendeeUids
	 * @return array<int, array{email: string, name: string}>
	 */
	private function attendeeEmails(array $attendeeUids): array {
		$emails = [];
		foreach ($attendeeUids as $attendeeUid) {
			$user = $this->userManager->get($attendeeUid);
			if ($user !== null && ($user->getEMailAddress() ?? '') !== '') {
				$emails[] = ['email' => $user->getEMailAddress(), 'name' => $user->getDisplayName()];
			}
		}
		return $emails;
	}

	/**
	 * @return array<int, array{uid: string, displayName: string}>
	 */
	public function attendeesFor(Interview $interview): array {
		return array_map(
			fn (InterviewAttendee $a) => [
				'uid' => $a->getUid(),
				'displayName' => $this->userManager->getDisplayName($a->getUid()) ?? $a->getUid(),
			],
			$this->attendeeMapper->findForInterview($interview->getId()),
		);
	}

	private function publicUrl(Interview $interview): string {
		return $this->urlGenerator->linkToRouteAbsolute('recruiting.public.showInterview', [
			'token' => $interview->getPublicToken(),
		]);
	}

	/**
	 * @param array[] $rawSlots
	 * @return array<int, array{0: \DateTime, 1: \DateTime}>
	 * @throws ValidationException
	 */
	private function parseSlots(array $rawSlots, int $durationMin): array {
		$now = $this->timeFactory->getDateTime();
		$slots = [];
		$seen = [];
		foreach ($rawSlots as $raw) {
			$startRaw = (string)($raw['start'] ?? '');
			try {
				$start = new \DateTime($startRaw);
			} catch (\Exception) {
				throw new ValidationException($this->l10n->t('Invalid slot'));
			}
			$start->setTimezone(new \DateTimeZone('UTC'));
			if ($start <= $now) {
				throw new ValidationException($this->l10n->t('Slots must be in the future'));
			}
			$key = $start->format('c');
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$end = (clone $start)->modify('+' . $durationMin . ' minutes');
			$slots[] = [$start, $end];
		}
		if ($slots === []) {
			throw new ValidationException($this->l10n->t('Propose at least one time slot'));
		}
		if (count($slots) > 10) {
			throw new ValidationException($this->l10n->t('No more than 10 slots can be proposed'));
		}
		usort($slots, static fn ($a, $b) => $a[0] <=> $b[0]);
		return $slots;
	}

}
