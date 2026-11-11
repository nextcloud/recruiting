<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Proposes interview slots where all interviewers are free, based on the
 * Nextcloud Calendar free/busy data and each interviewer's working hours
 * (spec §4.4).
 */
class SlotFinderService {
	/** Working hours for interviewers who have not set their own: weekdays 9–17 */
	public const WORK_START_HOUR = 9;
	public const WORK_END_HOUR = 17;
	private const STEP_MINUTES = 30;
	private const HORIZON_DAYS = 14;
	private const MAX_CHECKS = 160;

	public function __construct(
		private ICalendarManager $calendarManager,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private WorkingHoursService $workingHours,
	) {
	}

	/**
	 * A slot qualifies when it lies within the working hours of every
	 * interviewer (their Nextcloud availability settings, or weekdays
	 * WORK_START_HOUR–WORK_END_HOUR in $timezone when they set none) and
	 * free/busy reports everybody, the organizer included, as free.
	 *
	 * @param string[] $attendeeUids interviewers (the organizer is always included)
	 * @return array<int, array{start: string, end: string}> up to $count slots, ISO 8601
	 */
	public function propose(IUser $organizer, array $attendeeUids, int $durationMin, string $timezone, int $count = 6): array {
		$durationMin = max(15, min(480, $durationMin));
		try {
			$tz = new \DateTimeZone($timezone);
		} catch (\Exception) {
			$tz = new \DateTimeZone('UTC');
		}

		$emails = [];
		foreach (array_unique(array_merge([$organizer->getUID()], $attendeeUids)) as $uid) {
			$email = $this->userManager->get($uid)?->getEMailAddress();
			if ($email !== null && $email !== '') {
				$emails[] = $email;
			}
		}

		$default = WorkingHours::weekdays(self::WORK_START_HOUR, self::WORK_END_HOUR, $tz);
		$hours = [];
		foreach (array_unique($attendeeUids !== [] ? $attendeeUids : [$organizer->getUID()]) as $uid) {
			$hours[] = $this->workingHours->forUser($uid) ?? $default;
		}

		// Start tomorrow, the candidate needs time to react
		$now = \DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime())->setTimezone($tz);
		$cursor = $now->modify('+1 day')->setTime(0, 0);

		$slots = [];
		$checks = 0;
		$horizon = $now->modify('+' . self::HORIZON_DAYS . ' days');

		while (count($slots) < $count && $cursor < $horizon && $checks < self::MAX_CHECKS) {
			$slotEnd = $cursor->modify('+' . $durationMin . ' minutes');
			if (!$this->withinWorkingHours($hours, $cursor, $slotEnd)) {
				$cursor = $cursor->modify('+' . self::STEP_MINUTES . ' minutes');
				continue;
			}

			$checks++;
			if ($this->allAvailable($organizer, $emails, $cursor, $slotEnd)) {
				$slots[] = [
					'start' => $cursor->format(\DateTimeInterface::ATOM),
					'end' => $slotEnd->format(\DateTimeInterface::ATOM),
				];
				// Leave a gap after a found slot so proposals spread over the day
				$cursor = $slotEnd->modify('+' . self::STEP_MINUTES . ' minutes');
			} else {
				$cursor = $cursor->modify('+' . self::STEP_MINUTES . ' minutes');
			}
		}
		return $slots;
	}

	/**
	 * Whether every interviewer is free for [$start, $end) right now —
	 * the last check before a booking. Only the interviewers count, not
	 * the organizer, who may book interviews without attending them.
	 *
	 * @param string[] $attendeeUids
	 */
	public function attendeesFree(IUser $organizer, array $attendeeUids, \DateTimeInterface $start, \DateTimeInterface $end): bool {
		$emails = [];
		foreach (array_unique($attendeeUids) as $uid) {
			$email = $this->userManager->get($uid)?->getEMailAddress();
			if ($email !== null && $email !== '') {
				$emails[] = strtolower($email);
			}
		}
		if ($emails === []) {
			return true;
		}
		try {
			$results = $this->calendarManager->checkAvailability($start, $end, $organizer, $emails);
		} catch (\Exception) {
			// Same as when proposing: free/busy trouble must not block booking
			return true;
		}
		foreach ($results as $result) {
			if (in_array(strtolower($result->getAttendeeEmail()), $emails, true) && !$result->isAvailable()) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param WorkingHours[] $hours
	 */
	private function withinWorkingHours(array $hours, \DateTimeImmutable $start, \DateTimeImmutable $end): bool {
		foreach ($hours as $workingHours) {
			if (!$workingHours->contains($start, $end)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string[] $emails
	 */
	private function allAvailable(IUser $organizer, array $emails, \DateTimeImmutable $start, \DateTimeImmutable $end): bool {
		if ($emails === []) {
			return true;
		}
		try {
			$results = $this->calendarManager->checkAvailability($start, $end, $organizer, $emails);
		} catch (\Exception) {
			// If free/busy cannot be computed, do not block scheduling
			return true;
		}
		foreach ($results as $result) {
			if (!$result->isAvailable()) {
				return false;
			}
		}
		return true;
	}
}
