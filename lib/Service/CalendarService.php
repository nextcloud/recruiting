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
	 * Remove the interview event again.
	 *
	 * OCP\Calendar can only *create* objects — `createFromString()` refuses an
	 * existing UID — so cancellation goes through the CalDAV backend, which is
	 * also what makes the scheduling plugin send the iTIP CANCEL to every
	 * attendee. Without this the event would quietly stay in everyone's
	 * calendar after the interview was called off.
	 */
	public function cancelEvent(IUser $organizer, string $objectFileName): void {
		if ($objectFileName === '') {
			return;
		}
		$backend = $this->calDavBackend();
		if ($backend === null) {
			$this->logger->warning('Calendar backend unavailable, interview event not cancelled');
			return;
		}

		try {
			$principal = 'principals/users/' . $organizer->getUID();
			foreach ($backend->getCalendarsForUser($principal) as $calendar) {
				if ($backend->getCalendarObject($calendar['id'], $objectFileName) !== null) {
					$backend->deleteCalendarObject($calendar['id'], $objectFileName);
					return;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to cancel interview calendar event: ' . $e->getMessage(), ['exception' => $e]);
		}
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
