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
 * Nextcloud Calendar free/busy data (spec §4.4).
 */
class SlotFinderService {
	public const WORK_START_HOUR = 9;
	public const WORK_END_HOUR = 17;
	private const STEP_MINUTES = 30;
	private const HORIZON_DAYS = 14;
	private const MAX_CHECKS = 160;

	public function __construct(
		private ICalendarManager $calendarManager,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
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

		// Start tomorrow at the beginning of business hours, candidate needs time to react
		$now = \DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime())->setTimezone($tz);
		$cursor = $now->modify('+1 day')->setTime(self::WORK_START_HOUR, 0);

		$slots = [];
		$checks = 0;
		$horizon = $now->modify('+' . self::HORIZON_DAYS . ' days');

		while (count($slots) < $count && $cursor < $horizon && $checks < self::MAX_CHECKS) {
			// Skip weekends
			if ((int)$cursor->format('N') >= 6) {
				$cursor = $cursor->modify('+1 day')->setTime(self::WORK_START_HOUR, 0);
				continue;
			}
			$slotEnd = $cursor->modify('+' . $durationMin . ' minutes');
			$dayEnd = $cursor->setTime(self::WORK_END_HOUR, 0);
			if ($slotEnd > $dayEnd) {
				$cursor = $cursor->modify('+1 day')->setTime(self::WORK_START_HOUR, 0);
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
