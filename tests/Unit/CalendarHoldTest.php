<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Recruiting\Service\CalendarService;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Constants;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tentative holds for proposed slots in the interviewers' calendars.
 */
class CalendarHoldTest extends TestCase {
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('dev');
		return $user;
	}

	private function service(ICalendarManager $manager, ?CalDavBackend $backend = null): CalendarService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($backend ?? $this->createMock(CalDavBackend::class));
		return new CalendarService($manager, $container, new NullLogger());
	}

	public function testHoldIsATentativeEventWithoutAttendees(): void {
		$stored = [];
		$calendar = $this->createMock(ICreateFromString::class);
		$calendar->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$calendar->expects($this->once())->method('createFromStringMinimal')->willReturnCallback(function (string $name, string $ics) use (&$stored) {
			$stored = [$name, $ics];
		});
		$calendar->expects($this->never())->method('createFromString');
		$manager = $this->createMock(ICalendarManager::class);
		$manager->method('getCalendarsForPrincipal')->with('principals/users/dev')->willReturn([$calendar]);

		$this->assertTrue($this->service($manager)->createHold(
			$this->user(),
			'recruiting-hold-7',
			'Interview hold: Sales, Berlin',
			new \DateTimeImmutable('2026-08-18T11:00:00+02:00'),
			new \DateTimeImmutable('2026-08-18T12:00:00+02:00'),
		));

		[$name, $ics] = $stored;
		$this->assertSame('recruiting-hold-7.ics', $name);
		foreach (['UID:recruiting-hold-7', 'DTSTART:20260818T090000Z', 'DTEND:20260818T100000Z', 'SUMMARY:Interview hold: Sales\\, Berlin', 'STATUS:TENTATIVE', 'TRANSP:OPAQUE'] as $line) {
			$this->assertStringContainsString($line . "\r\n", $ics);
		}
		$this->assertStringNotContainsString('ATTENDEE', $ics);
		$this->assertStringNotContainsString('ORGANIZER', $ics);
	}

	public function testNoWritableCalendarMeansNoHold(): void {
		$manager = $this->createMock(ICalendarManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturn([]);
		$this->assertFalse($this->service($manager)->createHold($this->user(), 'recruiting-hold-7', 'Interview hold', new \DateTime(), new \DateTime()));
	}

	public function testStorageErrorsAreReportedAsNoHold(): void {
		$calendar = $this->createMock(ICreateFromString::class);
		$calendar->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$calendar->method('createFromStringMinimal')->willThrowException(new \RuntimeException('uid exists'));
		$manager = $this->createMock(ICalendarManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturn([$calendar]);
		$this->assertFalse($this->service($manager)->createHold($this->user(), 'recruiting-hold-7', 'Interview hold', new \DateTime(), new \DateTime()));
	}

	public function testRemovingAHoldDeletesItPastTheTrashBin(): void {
		$backend = $this->createMock(CalDavBackend::class);
		$backend->method('getCalendarObjectByUID')->with('principals/users/dev', 'recruiting-hold-7')->willReturn('work/recruiting-hold-7.ics');
		$backend->method('getCalendarsForUser')->willReturn([['id' => 1, 'uri' => 'personal'], ['id' => 4, 'uri' => 'work']]);
		$backend->expects($this->once())->method('deleteCalendarObject')->with(4, 'recruiting-hold-7.ics', CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
		$this->service($this->createMock(ICalendarManager::class), $backend)->removeHold('dev', 'recruiting-hold-7');
	}

	public function testRemovingAnUnknownHoldDoesNothing(): void {
		$backend = $this->createMock(CalDavBackend::class);
		$backend->method('getCalendarObjectByUID')->willReturn(null);
		$backend->expects($this->never())->method('deleteCalendarObject');
		$this->service($this->createMock(ICalendarManager::class), $backend)->removeHold('dev', 'recruiting-hold-7');
	}
}
