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
use OCP\AppFramework\Db\DoesNotExistException;
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
	/** Days after its end during which an interview's public link still works */
	public const PUBLIC_LINK_GRACE_DAYS = 7;

	/** Length cap for a message the candidate sends from the public page */
	public const CANDIDATE_MESSAGE_LIMIT = 2000;

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
			throw new NotPermittedException($this->l10n->t('No session user'));
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
		$holdSlots = (bool)($data['holdSlots'] ?? true);

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
		$interview->setHoldSlots($holdSlots);
		$interview = $this->interviewMapper->insert($interview);

		$insertedSlots = $this->insertSlots($interview, $slots);
		foreach ($attendeeUids as $attendeeUid) {
			$attendee = new InterviewAttendee();
			$attendee->setInterviewId($interview->getId());
			$attendee->setUid($attendeeUid);
			$this->attendeeMapper->insert($attendee);
		}
		$openingTitle = $this->openingMapper->findTitle($candidate->getOpeningId());
		$this->placeHolds($interview, $insertedSlots, $attendeeUids, $openingTitle);

		$this->timeline->record($candidateId, TimelineEvent::TYPE_INTERVIEW_PROPOSED, $uid, [
			'title' => $title,
			'slotCount' => count($slots),
		]);
		$this->activity->publish($candidate, 'interview_scheduled', $uid, ['title' => $title]);
		$this->notifications->notify($attendeeUids, NotificationService::SUBJECT_INTERVIEW_ASSIGNED, $candidate, $uid, [
			'interviewTitle' => $title,
		]);

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
	 * Send the invite mail — the first one or the one with new times. The
	 * public link is what makes self-scheduling work, so if the edited mail
	 * lost it we append it again. The send time starts the clock for the
	 * nudge (InterviewReminderService::NUDGE_AFTER_DAYS).
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
		$interview->setInvitedAt($this->timeFactory->getDateTime());
		$this->interviewMapper->update($interview);
		return $this->serialize($interview);
	}

	/**
	 * "Propose other times": replace the slots of a proposed or confirmed
	 * interview and return it with the prefilled mail draft. The public link
	 * stays the same. A confirmed interview goes back to "proposed": its
	 * calendar event is removed the same way cancelling removes it, while
	 * its Talk room is kept and reused once the candidate picks a new time.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function reschedule(string $uid, int $interviewId, array $data): array {
		$interview = $this->interviewMapper->find($interviewId);
		$candidate = $this->candidateMapper->find($interview->getCandidateId());
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		if (!in_array($interview->getStatus(), [Interview::STATUS_PROPOSED, Interview::STATUS_CONFIRMED], true)) {
			throw new ValidationException($this->l10n->t('Only an interview that is awaiting the candidate or confirmed can get new times'));
		}
		if ($candidate->getAnonymizedAt() !== null || $candidate->isTerminal()) {
			throw new ValidationException($this->l10n->t('This application is already closed.'));
		}
		$slots = $this->parseSlots($data['slots'] ?? [], $interview->getDurationMin());
		$attendeeUids = $this->attendeeUids($interview);
		$openingTitle = $this->openingMapper->findTitle($candidate->getOpeningId());
		$previousStart = $interview->getStartAt();

		if ($interview->getCalendarUid() !== '') {
			$organizer = $this->userManager->get($interview->getCreatedBy());
			if ($organizer !== null) {
				$this->calendar->cancelEvent($organizer, $interview->getCalendarUid(), $attendeeUids);
			}
			$interview->setCalendarUid('');
		}
		$this->releaseHolds($interview, $this->slotMapper->findForInterview($interview->getId()), $attendeeUids);
		$this->slotMapper->deleteForInterview($interview->getId());

		$interview->setStatus(Interview::STATUS_PROPOSED);
		$interview->setStartAt(null);
		$interview->setEndAt(null);
		$interview->setReminderSentAt(null);
		if (array_key_exists('holdSlots', $data)) {
			$interview->setHoldSlots((bool)$data['holdSlots']);
		}
		$this->interviewMapper->update($interview);
		$this->placeHolds($interview, $this->insertSlots($interview, $slots), $attendeeUids, $openingTitle);

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_INTERVIEW_RESCHEDULED, $uid, [
			'title' => $interview->getTitle(),
			'slotCount' => count($slots),
			'previousStartAt' => $previousStart?->format(\DateTimeInterface::ATOM),
		]);
		$this->notifications->notify($attendeeUids, NotificationService::SUBJECT_INTERVIEW_RESCHEDULED, $candidate, $uid, [
			'interviewTitle' => $interview->getTitle(),
		]);

		return [
			'interview' => $this->serialize($interview),
			'inviteDraft' => $this->mail->preview($candidate, $openingTitle, MailTemplate::TYPE_INTERVIEW_RESCHEDULE, null, $uid, [
				'interview_title' => $interview->getTitle(),
				'interview_link' => $this->publicUrl($interview),
			]),
		];
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
		if ($slot->getUnavailable()) {
			throw new ValidationException($this->l10n->t('This time is no longer available, please pick another one.'));
		}
		// Take the interview out of "proposed" atomically: a second, parallel
		// submission of the public page must not book a second calendar event.
		if (!$this->interviewMapper->claimForConfirmation($interview->getId())) {
			throw new ValidationException($this->l10n->t('This interview is no longer awaiting confirmation'));
		}
		$candidate = $this->candidateMapper->find($interview->getCandidateId());
		$attendeeUids = $this->attendeeUids($interview);
		$organizer = $this->userManager->get($interview->getCreatedBy());

		// Re-check the panel's free/busy: the slot was free when it was
		// proposed, but may have been booked since. This interview's own
		// holds on the chosen time must not count, so they go first.
		$slots = $this->slotMapper->findForInterview($interview->getId());
		$overlapping = array_values(array_filter(
			$slots,
			static fn (InterviewSlot $other) => $other->getStartAt() < $slot->getEndAt() && $other->getEndAt() > $slot->getStartAt(),
		));
		$this->releaseHolds($interview, $overlapping, $attendeeUids);
		if ($organizer !== null && !$this->slotFinder->attendeesFree($organizer, $attendeeUids, $slot->getStartAt(), $slot->getEndAt())) {
			$slot->setUnavailable(true);
			$this->slotMapper->update($slot);
			$this->placeHolds(
				$interview,
				array_filter($overlapping, static fn (InterviewSlot $other) => $other->getId() !== $slot->getId()),
				$attendeeUids,
				$this->openingMapper->findTitle($candidate->getOpeningId()),
			);
			$this->interviewMapper->releaseConfirmation($interview->getId());
			throw new ValidationException($this->l10n->t('This time is no longer available, please pick another one.'));
		}
		$this->releaseHolds(
			$interview,
			array_filter($slots, static fn (InterviewSlot $other) => !in_array($other, $overlapping, true)),
			$attendeeUids,
		);

		$slot->setChosen(true);
		$this->slotMapper->update($slot);

		$interview->setStartAt($slot->getStartAt());
		$interview->setEndAt($slot->getEndAt());
		$interview->setStatus(Interview::STATUS_CONFIRMED);

		// Talk room for video interviews; one kept from before a reschedule is reused
		if ($interview->getIsVideo() && $interview->getTalkToken() === '') {
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
			// Opens the candidate in the app; access is checked there as always
			$description .= "\n" . $this->l10n->t('Open in Recruiting: %s', [$this->candidateUrl($candidate)]);
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
	 * The candidate says none of the proposed times work. Nothing changes
	 * automatically: the request lands on the timeline and with the
	 * organizer and interviewers, who propose new times.
	 *
	 * @throws ValidationException
	 */
	public function declineSlots(Interview $interview, string $message): void {
		if ($interview->getStatus() !== Interview::STATUS_PROPOSED) {
			throw new ValidationException($this->l10n->t('This interview is no longer awaiting confirmation'));
		}
		$this->recordCandidateRequest($interview, $message, TimelineEvent::TYPE_INTERVIEW_SLOTS_DECLINED, NotificationService::SUBJECT_INTERVIEW_SLOTS_DECLINED);
	}

	/**
	 * The candidate asks to move a confirmed interview. The booking stays
	 * until the team acts on the request.
	 *
	 * @throws ValidationException
	 */
	public function requestReschedule(Interview $interview, string $message): void {
		if ($interview->getStatus() !== Interview::STATUS_CONFIRMED) {
			throw new ValidationException($this->l10n->t('Only a confirmed interview can be rescheduled'));
		}
		$this->recordCandidateRequest($interview, $message, TimelineEvent::TYPE_INTERVIEW_RESCHEDULE_REQUESTED, NotificationService::SUBJECT_INTERVIEW_RESCHEDULE_REQUESTED);
	}

	/**
	 * @throws ValidationException
	 */
	private function recordCandidateRequest(Interview $interview, string $message, string $type, string $subject): void {
		$candidate = $this->candidateMapper->find($interview->getCandidateId());
		if ($candidate->getAnonymizedAt() !== null || $candidate->isTerminal()) {
			throw new ValidationException($this->l10n->t('This application is already closed.'));
		}
		$this->timeline->record($candidate->getId(), $type, null, [
			'title' => $interview->getTitle(),
			'message' => mb_substr(trim($message), 0, self::CANDIDATE_MESSAGE_LIMIT),
		]);
		$attendeeUids = $this->attendeeUids($interview);
		$this->notifications->notify(
			array_unique(array_merge($attendeeUids, [$interview->getCreatedBy()])),
			$subject,
			$candidate,
			null,
			['interviewTitle' => $interview->getTitle()],
		);
	}

	/**
	 * Cancel an interview. With a (previewed, possibly edited) mail it goes
	 * to the candidate first, so a failed send leaves the interview as it
	 * was instead of cancelled with the candidate uninformed.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function cancel(string $uid, int $interviewId, ?string $mailSubject = null, ?string $mailBody = null): array {
		$interview = $this->interviewMapper->find($interviewId);
		$candidate = $this->candidateMapper->find($interview->getCandidateId());
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		if ($mailSubject !== null && $mailBody !== null) {
			if (!in_array($interview->getStatus(), [Interview::STATUS_PROPOSED, Interview::STATUS_CONFIRMED], true)) {
				throw new ValidationException($this->l10n->t('This interview can no longer be cancelled'));
			}
			$this->mail->sendToCandidate($candidate, $mailSubject, $mailBody, $uid);
		}
		$this->doCancel($interview, $candidate, $uid, notify: true);
		return $this->serialize($this->interviewMapper->find($interviewId));
	}

	/**
	 * The prefilled cancellation mail for the cancel dialog.
	 *
	 * @return array{subject: string, body: string, to: string}
	 * @throws NotPermittedException|ValidationException
	 */
	public function cancelPreview(string $uid, int $interviewId): array {
		$interview = $this->interviewMapper->find($interviewId);
		$candidate = $this->candidateMapper->find($interview->getCandidateId());
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		return $this->mail->preview(
			$candidate,
			$this->openingMapper->findTitle($candidate->getOpeningId()),
			MailTemplate::TYPE_INTERVIEW_CANCELLED,
			null,
			$uid,
			['interview_title' => $interview->getTitle()],
		);
	}

	/**
	 * Cancel the interviews of a candidate who withdrew: those awaiting a
	 * pick and confirmed ones that have not ended. The interviewers are
	 * notified; the candidate gets no mail, they left on their own.
	 *
	 * @return int the number of interviews cancelled
	 */
	public function cancelOpenFor(Candidate $candidate, ?string $actorUid): int {
		$now = $this->timeFactory->getDateTime();
		$cancelled = 0;
		foreach ($this->interviewMapper->findForCandidate($candidate->getId()) as $interview) {
			$open = $interview->getStatus() === Interview::STATUS_PROPOSED
				|| ($interview->getStatus() === Interview::STATUS_CONFIRMED && ($interview->getEndAt() === null || $interview->getEndAt() > $now));
			if ($open) {
				$this->doCancel($interview, $candidate, $actorUid, notify: true);
				$cancelled++;
			}
		}
		return $cancelled;
	}

	/**
	 * Confirmed interviews whose end time has passed become "done" (run
	 * hourly by InterviewReminderJob).
	 *
	 * @return int the number of interviews marked done
	 */
	public function markPastAsDone(): int {
		return $this->interviewMapper->markEndedAsDone($this->timeFactory->getDateTime());
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
	 * Take the candidate out of every interview's calendar traces — used by
	 * the GDPR hard delete and by anonymization.
	 *
	 * Upcoming interviews are cancelled (events removed for organizer and
	 * attendees, Talk room deleted). Past events stay on record but are
	 * rewritten to neutral wording without the name or the Talk link, and
	 * their Talk rooms are deleted. While the process is active none of this
	 * runs: interviewers need the name in their calendar.
	 *
	 * @param bool $revokeLinks give every interview a fresh, never sent
	 *                          public token, so old links stop working
	 */
	public function scrubForCandidate(Candidate $candidate, bool $revokeLinks): void {
		$now = $this->timeFactory->getDateTime();
		$openingTitle = $this->openingMapper->findTitle($candidate->getOpeningId());
		foreach ($this->interviewMapper->findForCandidate($candidate->getId()) as $interview) {
			$status = $interview->getStatus();
			$upcoming = in_array($status, [Interview::STATUS_PROPOSED, Interview::STATUS_CONFIRMED], true)
				&& ($interview->getStartAt() === null || $interview->getStartAt() > $now);
			if ($upcoming) {
				$this->doCancel($interview, $candidate, null, notify: false, permanently: true);
			} elseif ($status !== Interview::STATUS_CANCELLED) {
				$this->neutralizeCalendarEvent($interview, $openingTitle);
				if ($interview->getTalkToken() !== '') {
					$this->talk->deleteRoom($interview->getTalkToken());
					$interview->setTalkToken('');
					$interview->setTalkLink('');
				}
			}
			if ($revokeLinks) {
				$interview->setPublicToken($this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC));
			}
			$this->interviewMapper->update($interview);
		}
	}

	/**
	 * The interview behind a public link, or null when the link has expired:
	 * cancelled interviews, interviews that ended more than
	 * PUBLIC_LINK_GRACE_DAYS ago, and candidates who were rejected, withdrew
	 * or were anonymized. Otherwise the page would keep showing the name,
	 * the opening and the Talk link indefinitely.
	 */
	public function findByPublicToken(string $token): ?Interview {
		$interview = $this->interviewMapper->findByToken($token);
		if ($interview === null || $interview->getStatus() === Interview::STATUS_CANCELLED) {
			return null;
		}
		$endAt = $interview->getEndAt();
		if ($endAt !== null) {
			$expiry = \DateTimeImmutable::createFromInterface($endAt)->modify('+' . self::PUBLIC_LINK_GRACE_DAYS . ' days');
			if ($expiry < $this->timeFactory->getDateTime()) {
				return null;
			}
		}
		try {
			$candidate = $this->candidateMapper->find($interview->getCandidateId());
		} catch (DoesNotExistException) {
			return null;
		}
		if ($candidate->getAnonymizedAt() !== null
			|| in_array($candidate->getStage(), [Candidate::STAGE_REJECTED, Candidate::STAGE_WITHDRAWN], true)) {
			return null;
		}
		return $interview;
	}

	private function neutralizeCalendarEvent(Interview $interview, ?string $openingTitle): void {
		if ($interview->getCalendarUid() === '') {
			return;
		}
		$organizer = $this->userManager->get($interview->getCreatedBy());
		if ($organizer === null) {
			return;
		}
		$attendeeUids = $this->attendeeUids($interview);
		$this->calendar->neutralizeEvent(
			$organizer,
			$interview->getCalendarUid(),
			$attendeeUids,
			$openingTitle !== null ? $this->l10n->t('Interview: %s', [$openingTitle]) : $this->l10n->t('Interview'),
			$openingTitle !== null ? $this->l10n->t('Opening: %s', [$openingTitle]) : '',
			$interview->getIsVideo() ? null : $interview->getLocation(),
		);
	}

	/**
	 * @param bool $permanently remove calendar events past the calendar
	 *                          trash bin (data protection)
	 */
	private function doCancel(Interview $interview, Candidate $candidate, ?string $actorUid, bool $notify, bool $permanently = false): void {
		if ($interview->getStatus() === Interview::STATUS_CANCELLED) {
			return;
		}
		$attendeeUids = $this->attendeeUids($interview);

		if ($interview->getCalendarUid() !== '') {
			$organizer = $this->userManager->get($interview->getCreatedBy());
			if ($organizer !== null) {
				$this->calendar->cancelEvent($organizer, $interview->getCalendarUid(), $attendeeUids, $permanently);
			}
		}
		$this->releaseHolds($interview, $this->slotMapper->findForInterview($interview->getId()), $attendeeUids);
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
			static fn (InterviewSlot $slot) => $slot->getStartAt() > $now && !$slot->getUnavailable(),
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

	/**
	 * The candidate in the app (the notification deep link).
	 */
	public function candidateUrl(Candidate $candidate): string {
		return $this->urlGenerator->linkToRouteAbsolute('recruiting.page.index') . '#/candidate/' . $candidate->getId();
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
	 * @param array<int, array{0: \DateTime, 1: \DateTime}> $slots
	 * @return InterviewSlot[]
	 */
	private function insertSlots(Interview $interview, array $slots): array {
		$inserted = [];
		foreach ($slots as [$start, $end]) {
			$slot = new InterviewSlot();
			$slot->setInterviewId($interview->getId());
			$slot->setStartAt($start);
			$slot->setEndAt($end);
			$inserted[] = $this->slotMapper->insert($slot);
		}
		return $inserted;
	}

	/**
	 * The calendar UID of a slot's hold — the same in every interviewer's
	 * calendar, so a hold can be found again without storing anything.
	 */
	private function holdUid(InterviewSlot $slot): string {
		return 'recruiting-hold-' . $slot->getId();
	}

	/**
	 * Hold the upcoming, available slots as tentative events in every
	 * interviewer's calendar, when the interview asks for it. The title
	 * names the opening only, never the candidate.
	 *
	 * @param InterviewSlot[] $slots
	 * @param string[] $attendeeUids
	 */
	private function placeHolds(Interview $interview, array $slots, array $attendeeUids, ?string $openingTitle): void {
		if (!$interview->getHoldSlots()) {
			return;
		}
		$summary = $openingTitle !== null
			? $this->l10n->t('Interview hold: %s', [$openingTitle])
			: $this->l10n->t('Interview hold');
		$now = $this->timeFactory->getDateTime();
		foreach ($attendeeUids as $attendeeUid) {
			$user = $this->userManager->get($attendeeUid);
			if ($user === null) {
				continue;
			}
			foreach ($slots as $slot) {
				if (!$slot->getUnavailable() && $slot->getStartAt() > $now) {
					$this->calendar->createHold($user, $this->holdUid($slot), $summary, $slot->getStartAt(), $slot->getEndAt());
				}
			}
		}
	}

	/**
	 * @param InterviewSlot[] $slots
	 * @param string[] $attendeeUids
	 */
	private function releaseHolds(Interview $interview, array $slots, array $attendeeUids): void {
		if (!$interview->getHoldSlots()) {
			return;
		}
		foreach ($attendeeUids as $attendeeUid) {
			foreach ($slots as $slot) {
				$this->calendar->removeHold($attendeeUid, $this->holdUid($slot));
			}
		}
	}

	public function publicUrl(Interview $interview): string {
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
