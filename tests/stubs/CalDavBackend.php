<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAV\CalDAV;

/**
 * Stand-in for the dav app's backend, which the unit tests cannot load: only
 * the methods CalendarService uses, with the server's signatures.
 */
class CalDavBackend {
	public const CALENDAR_TYPE_CALENDAR = 0;

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getCalendarsForUser($principalUri) {
		return [];
	}

	public function getCalendarObject($calendarId, $objectUri, int $calendarType = self::CALENDAR_TYPE_CALENDAR): ?array {
		return null;
	}

	public function getCalendarObjectByUID($principalUri, $uid, $calendarUri = null) {
		return null;
	}

	public function updateCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = self::CALENDAR_TYPE_CALENDAR) {
		return '';
	}

	public function deleteCalendarObject($calendarId, $objectUri, $calendarType = self::CALENDAR_TYPE_CALENDAR, bool $forceDeletePermanently = false) {
	}
}
