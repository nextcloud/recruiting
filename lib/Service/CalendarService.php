<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\Calendar\CalendarEventStatus;
use OCP\Calendar\ICalendarIsEnabled;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Constants;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates and cancels the interview events in the organizer's calendar.
 * Attendees receive standard iTIP invitations from the CalDAV layer, so
 * they can accept/decline in any calendar client (spec §4.4).
 */
class CalendarService {
	public function __construct(
		private ICalendarManager $calendarManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<int, array{email: string, name: string}> $attendees
	 * @return ?string the event object filename, or null when no writable calendar exists
	 */
	public function createEvent(
		IUser $organizer,
		string $summary,
		string $description,
		\DateTimeInterface $start,
		\DateTimeInterface $end,
		string $location,
		array $attendees,
	): ?string {
		$calendar = $this->pickCalendar($organizer);
		if ($calendar === null) {
			$this->logger->warning('No writable calendar found for user ' . $organizer->getUID() . ', interview not synced');
			return null;
		}

		$builder = $this->calendarManager->createEventBuilder();
		$builder->setSummary($summary)
			->setDescription($description)
			->setStartDate($start)
			->setEndDate($end)
			->setStatus(CalendarEventStatus::CONFIRMED);
		if ($location !== '') {
			$builder->setLocation($location);
		}

		$organizerEmail = $organizer->getEMailAddress();
		if ($organizerEmail !== null && $organizerEmail !== '') {
			$builder->setOrganizer($organizerEmail, $organizer->getDisplayName());
			foreach ($attendees as $attendee) {
				if ($attendee['email'] !== '' && $attendee['email'] !== $organizerEmail) {
					$builder->addAttendee($attendee['email'], $attendee['name']);
				}
			}
		}

		try {
			return $builder->createInCalendar($calendar);
		} catch (\Exception $e) {
			$this->logger->error('Failed to create interview calendar event: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Remove the interview event again: the organizer's object and every
	 * internal attendee's copy.
	 *
	 * OCP\Calendar can only *create* objects — `createFromString()` refuses an
	 * existing UID — so this goes through the CalDAV backend. Backend writes
	 * bypass the scheduling plugin, so no iTIP CANCEL is sent and the copies
	 * that scheduling delivered into the attendees' calendars at creation
	 * have to be removed one by one.
	 *
	 * @param string[] $attendeeUids
	 * @param bool $permanently skip the calendar trash bin, so the old event
	 *                          text does not linger there (data protection)
	 */
	public function cancelEvent(IUser $organizer, string $objectFileName, array $attendeeUids = [], bool $permanently = false): void {
		$backend = $this->calDavBackend();
		if ($objectFileName === '' || $backend === null) {
			if ($objectFileName !== '') {
				$this->logger->warning('Calendar backend unavailable, interview event not cancelled');
			}
			return;
		}

		try {
			foreach ($this->copiesOf($backend, $organizer, $objectFileName, $attendeeUids) as [$calendarId, $objectUri]) {
				$backend->deleteCalendarObject($calendarId, $objectUri, CalDavBackend::CALENDAR_TYPE_CALENDAR, $permanently);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to cancel interview calendar event: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Replace the text of a past interview event — in the organizer's
	 * calendar and in every internal attendee's copy — with neutral wording,
	 * so that the candidate's name and the Talk link disappear while the
	 * appointment itself stays on record.
	 *
	 * @param string[] $attendeeUids
	 * @param ?string $location null removes the location
	 */
	public function neutralizeEvent(IUser $organizer, string $objectFileName, array $attendeeUids, string $summary, string $description, ?string $location): void {
		$backend = $this->calDavBackend();
		if ($objectFileName === '' || $backend === null) {
			if ($objectFileName !== '') {
				$this->logger->warning('Calendar backend unavailable, interview event not neutralized');
			}
			return;
		}

		try {
			foreach ($this->copiesOf($backend, $organizer, $objectFileName, $attendeeUids) as [$calendarId, $objectUri]) {
				$object = $backend->getCalendarObject($calendarId, $objectUri);
				if ($object === null) {
					continue;
				}
				$backend->updateCalendarObject(
					$calendarId,
					$objectUri,
					IcsScrubber::neutralize((string)$object['calendardata'], $summary, $description, $location),
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to neutralize interview calendar event: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Every stored copy of one interview event.
	 *
	 * The event builder names the object `<UID>.ics`, and scheduling delivers
	 * attendee copies under the same UID, so the copies are found by UID
	 * even when the organizer has already removed their own.
	 *
	 * @param string[] $attendeeUids
	 * @return list<array{0: int, 1: string}> calendar id and object URI
	 */
	private function copiesOf(CalDavBackend $backend, IUser $organizer, string $objectFileName, array $attendeeUids): array {
		$copies = [];
		foreach ($backend->getCalendarsForUser($this->principal($organizer->getUID())) as $calendar) {
			if ($backend->getCalendarObject((int)$calendar['id'], $objectFileName) !== null) {
				$copies[] = [(int)$calendar['id'], $objectFileName];
				break;
			}
		}

		$eventUid = preg_replace('/\.ics$/i', '', $objectFileName) ?? $objectFileName;
		foreach (array_unique($attendeeUids) as $attendeeUid) {
			if ($attendeeUid === $organizer->getUID()) {
				continue;
			}
			$principal = $this->principal($attendeeUid);
			$path = $backend->getCalendarObjectByUID($principal, $eventUid);
			if (!is_string($path) || !str_contains($path, '/')) {
				continue;
			}
			[$calendarUri, $objectUri] = explode('/', $path, 2);
			foreach ($backend->getCalendarsForUser($principal) as $calendar) {
				if (($calendar['uri'] ?? null) === $calendarUri) {
					$copies[] = [(int)$calendar['id'], $objectUri];
					break;
				}
			}
		}
		return $copies;
	}

	private function principal(string $uid): string {
		return 'principals/users/' . $uid;
	}

	/**
	 * The dav app ships the only API that can modify calendar objects; it is
	 * always present on a normal instance, but resolve it defensively.
	 */
	private function calDavBackend(): ?CalDavBackend {
		if (!class_exists(CalDavBackend::class)) {
			return null;
		}
		try {
			/** @var CalDavBackend $backend */
			$backend = $this->container->get(CalDavBackend::class);
			return $backend;
		} catch (\Throwable) {
			return null;
		}
	}

	private function pickCalendar(IUser $user): ?ICreateFromString {
		$calendars = $this->calendarManager->getCalendarsForPrincipal('principals/users/' . $user->getUID());
		foreach ($calendars as $calendar) {
			if (!$calendar instanceof ICreateFromString) {
				continue;
			}
			if ($calendar instanceof ICalendarIsEnabled && !$calendar->isEnabled()) {
				continue;
			}
			if ($calendar instanceof ICalendarIsWritable && !$calendar->isWritable()) {
				continue;
			}
			if (($calendar->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
				continue;
			}
			return $calendar;
		}
		return null;
	}
}
