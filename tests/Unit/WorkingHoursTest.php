<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Service\WorkingHours;
use PHPUnit\Framework\TestCase;

/**
 * Reading the VAVAILABILITY that Personal settings → Availability stores.
 */
class WorkingHoursTest extends TestCase {
	/** The shape the availability settings write: VTIMEZONE + weekly AVAILABLE blocks */
	private const NEXTCLOUD = "BEGIN:VCALENDAR\r\n"
		. "PRODID:Nextcloud DAV app\r\n"
		. "BEGIN:VTIMEZONE\r\n"
		. "TZID:Europe/Berlin\r\n"
		. "BEGIN:STANDARD\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nTZNAME:CET\r\nDTSTART:19701025T030000\r\nEND:STANDARD\r\n"
		. "END:VTIMEZONE\r\n"
		. "BEGIN:VAVAILABILITY\r\n"
		. "BEGIN:AVAILABLE\r\n"
		. "DTSTART;TZID=Europe/Berlin:20231030T080000\r\n"
		. "DTEND;TZID=Europe/Berlin:20231030T120000\r\n"
		. "UID:a1\r\n"
		. "RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,\r\n TH\r\n"
		. "END:AVAILABLE\r\n"
		. "BEGIN:AVAILABLE\r\n"
		. "DTSTART;TZID=Europe/Berlin:20231030T130000\r\n"
		. "DTEND;TZID=Europe/Berlin:20231030T160000\r\n"
		. "UID:a2\r\n"
		. "RRULE:FREQ=WEEKLY;BYDAY=TU\r\n"
		. "END:AVAILABLE\r\n"
		. "END:VAVAILABILITY\r\n"
		. "END:VCALENDAR\r\n";

	private function at(string $time, int $minutes = 60): array {
		$start = new \DateTimeImmutable($time);
		return [$start, $start->modify('+' . $minutes . ' minutes')];
	}

	public function testReadsWeeklyBlocksInTheirTimezone(): void {
		$hours = WorkingHours::fromVAvailability(self::NEXTCLOUD);
		$this->assertNotNull($hours);

		// Monday 2026-08-17, Berlin is UTC+2 in August: 08:00 local = 06:00 UTC
		$this->assertTrue($hours->contains(...$this->at('2026-08-17T06:00:00+00:00')));
		$this->assertTrue($hours->contains(...$this->at('2026-08-17T09:00:00+00:00')), '11:00–12:00 local ends exactly at the block end');
		$this->assertFalse($hours->contains(...$this->at('2026-08-17T05:30:00+00:00')), 'starts before 08:00 local');
		$this->assertFalse($hours->contains(...$this->at('2026-08-17T09:30:00+00:00')), 'runs past 12:00 local');
		// The folded BYDAY line still lists Thursday
		$this->assertTrue($hours->contains(...$this->at('2026-08-20T07:00:00+00:00')));
	}

	public function testAfternoonBlockOnlyOnItsDay(): void {
		$hours = WorkingHours::fromVAvailability(self::NEXTCLOUD);
		$this->assertNotNull($hours);
		// Tuesday 14:00 local
		$this->assertTrue($hours->contains(...$this->at('2026-08-18T12:00:00+00:00')));
		// Monday 14:00 local: only the morning block applies
		$this->assertFalse($hours->contains(...$this->at('2026-08-17T12:00:00+00:00')));
	}

	public function testWeekendIsOutsideWhenNotListed(): void {
		$hours = WorkingHours::fromVAvailability(self::NEXTCLOUD);
		$this->assertNotNull($hours);
		$this->assertFalse($hours->contains(...$this->at('2026-08-22T07:00:00+00:00')));
	}

	public function testASlotCannotSpanTwoBlocks(): void {
		$hours = WorkingHours::fromVAvailability(self::NEXTCLOUD);
		$this->assertNotNull($hours);
		// Tuesday 11:00–14:00 local touches both blocks but leaves both
		$this->assertFalse($hours->contains(...$this->at('2026-08-18T09:00:00+00:00', 180)));
	}

	public function testUtcTimesAndWeekdayFromStartWhenByDayIsMissing(): void {
		$ics = "BEGIN:VCALENDAR\nBEGIN:VAVAILABILITY\nBEGIN:AVAILABLE\n"
			. "DTSTART:20260817T100000Z\nDTEND:20260817T140000Z\nRRULE:FREQ=WEEKLY\n"
			. "END:AVAILABLE\nEND:VAVAILABILITY\nEND:VCALENDAR\n";
		$hours = WorkingHours::fromVAvailability($ics);
		$this->assertNotNull($hours);
		// 2026-08-17 is a Monday; the following Monday works, Tuesday not
		$this->assertTrue($hours->contains(...$this->at('2026-08-24T10:00:00+00:00')));
		$this->assertFalse($hours->contains(...$this->at('2026-08-25T10:00:00+00:00')));
	}

	public function testBlockEndingAtMidnight(): void {
		$ics = "BEGIN:VCALENDAR\nBEGIN:VAVAILABILITY\nBEGIN:AVAILABLE\n"
			. "DTSTART:20260817T200000Z\nDTEND:20260818T000000Z\nRRULE:FREQ=WEEKLY;BYDAY=MO\n"
			. "END:AVAILABLE\nEND:VAVAILABILITY\nEND:VCALENDAR\n";
		$hours = WorkingHours::fromVAvailability($ics);
		$this->assertNotNull($hours);
		$this->assertTrue($hours->contains(...$this->at('2026-08-24T23:00:00+00:00')));
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unusable(): array {
		$wrap = static fn (string $available) => "BEGIN:VCALENDAR\nBEGIN:VAVAILABILITY\n" . $available . "END:VAVAILABILITY\nEND:VCALENDAR\n";
		return [
			'cleared' => [$wrap('')],
			'one-off range' => [$wrap("BEGIN:AVAILABLE\nDTSTART:20260817T100000Z\nDTEND:20260817T140000Z\nEND:AVAILABLE\n")],
			'daily rule' => [$wrap("BEGIN:AVAILABLE\nDTSTART:20260817T100000Z\nDTEND:20260817T140000Z\nRRULE:FREQ=DAILY\nEND:AVAILABLE\n")],
			'end before start' => [$wrap("BEGIN:AVAILABLE\nDTSTART:20260817T140000Z\nDTEND:20260817T100000Z\nRRULE:FREQ=WEEKLY\nEND:AVAILABLE\n")],
			'date only' => [$wrap("BEGIN:AVAILABLE\nDTSTART;VALUE=DATE:20260817\nDTEND;VALUE=DATE:20260818\nRRULE:FREQ=WEEKLY\nEND:AVAILABLE\n")],
			'garbage' => ['not an icalendar object'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('unusable')]
	public function testUnusableAvailabilityCountsAsNotSet(string $ics): void {
		$this->assertNull(WorkingHours::fromVAvailability($ics));
	}

	public function testUnknownTimezoneFallsBackToTheCalendarTimezone(): void {
		$ics = "BEGIN:VCALENDAR\nBEGIN:VTIMEZONE\nTZID:America/New_York\nEND:VTIMEZONE\nBEGIN:VAVAILABILITY\nBEGIN:AVAILABLE\n"
			. "DTSTART;TZID=Mars/Olympus:20260817T090000\nDTEND;TZID=Mars/Olympus:20260817T170000\nRRULE:FREQ=WEEKLY;BYDAY=MO\n"
			. "END:AVAILABLE\nEND:VAVAILABILITY\nEND:VCALENDAR\n";
		$hours = WorkingHours::fromVAvailability($ics);
		$this->assertNotNull($hours);
		// 09:00 in New York (UTC-4 in August) = 13:00 UTC
		$this->assertTrue($hours->contains(...$this->at('2026-08-24T13:00:00+00:00')));
		$this->assertFalse($hours->contains(...$this->at('2026-08-24T09:00:00+00:00')));
	}

	public function testDefaultWeekdays(): void {
		$hours = WorkingHours::weekdays(9, 17, new \DateTimeZone('UTC'));
		$this->assertTrue($hours->contains(...$this->at('2026-08-17T09:00:00+00:00')));
		$this->assertTrue($hours->contains(...$this->at('2026-08-17T16:00:00+00:00')));
		$this->assertFalse($hours->contains(...$this->at('2026-08-17T16:30:00+00:00')));
		$this->assertFalse($hours->contains(...$this->at('2026-08-22T10:00:00+00:00')));
	}
}
