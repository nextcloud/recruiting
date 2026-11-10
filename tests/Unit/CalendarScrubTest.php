<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Recruiting\Service\CalendarService;
use OCA\Recruiting\Service\IcsScrubber;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Interview events must lose the candidate's name once the candidate is
 * deleted or anonymized — in the organizer's calendar and in the copies
 * scheduling delivered to the interviewers.
 */
class CalendarScrubTest extends TestCase {
	private const EVENT = "BEGIN:VCALENDAR\r\n"
		. "VERSION:2.0\r\n"
		. "PRODID:-//IDN nextcloud.com//Calendar app//EN\r\n"
		. "BEGIN:VEVENT\r\n"
		. "UID:abc-123\r\n"
		. "DTSTART:20260801T100000Z\r\n"
		. "DTEND:20260801T110000Z\r\n"
		. "SUMMARY:Interview — Jane Doe\r\n"
		. "DESCRIPTION:Candidate: Jane Doe\\nOpening: Backend Developer\\nhttps://cloud.e\r\n"
		. " xample.com/call/talk123\r\n"
		. "LOCATION:https://cloud.example.com/call/talk123\r\n"
		. "URL:https://cloud.example.com/call/talk123\r\n"
		. "ORGANIZER;CN=Boss:mailto:boss@example.com\r\n"
		. "ATTENDEE;CN=Dev;PARTSTAT=ACCEPTED:mailto:dev@example.com\r\n"
		. "BEGIN:VALARM\r\n"
		. "ACTION:DISPLAY\r\n"
		. "DESCRIPTION:Interview — Jane Doe\r\n"
		. "TRIGGER:-PT15M\r\n"
		. "END:VALARM\r\n"
		. "END:VEVENT\r\n"
		. "END:VCALENDAR\r\n";

	public function testNeutralizeRemovesNameAndTalkLink(): void {
		$result = IcsScrubber::neutralize(self::EVENT, 'Interview: Backend Developer', 'Opening: Backend Developer', null);

		$this->assertStringNotContainsString('Jane', $result);
		$this->assertStringNotContainsString('talk123', $result);
		$this->assertStringContainsString("SUMMARY:Interview: Backend Developer\r\n", $result);
		$this->assertStringContainsString("DESCRIPTION:Opening: Backend Developer\r\n", $result);
		$this->assertStringNotContainsString('LOCATION', $result);
		$this->assertStringNotContainsString('URL:', $result);
		// The alarm text repeats the summary, the appointment itself stays
		$this->assertStringContainsString("DESCRIPTION:Interview: Backend Developer\r\nTRIGGER:-PT15M", $result);
		foreach (['UID:abc-123', 'DTSTART:20260801T100000Z', 'ORGANIZER;CN=Boss:mailto:boss@example.com', 'ATTENDEE;CN=Dev;PARTSTAT=ACCEPTED:mailto:dev@example.com', 'END:VCALENDAR'] as $kept) {
			$this->assertStringContainsString($kept . "\r\n", $result);
		}
	}

	public function testNeutralizeKeepsAPhysicalLocationAndEscapesText(): void {
		$result = IcsScrubber::neutralize(self::EVENT, 'Interview: Sales, Berlin', '', 'Room 3; 2nd floor');
		$this->assertStringContainsString("SUMMARY:Interview: Sales\\, Berlin\r\n", $result);
		$this->assertStringContainsString("LOCATION:Room 3\\; 2nd floor\r\n", $result);
	}

	public function testLongLinesAreFoldedWithoutBreakingCharacters(): void {
		$summary = str_repeat('Ü', 60);
		$result = IcsScrubber::neutralize(self::EVENT, $summary, '', null);
		foreach (explode("\r\n", $result) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line));
			$this->assertTrue(mb_check_encoding($line, 'UTF-8'));
		}
		$unfolded = str_replace("\r\n ", '', $result);
		$this->assertStringContainsString('SUMMARY:' . $summary . "\r\n", $unfolded);
	}

	private function organizer(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('boss');
		return $user;
	}

	/**
	 * The organizer's object in calendar 1, the interviewer's copy in
	 * calendar 2 (found by UID), the second interviewer has none.
	 */
	private function backend(): CalDavBackend {
		$backend = $this->createMock(CalDavBackend::class);
		$backend->method('getCalendarsForUser')->willReturnCallback(static fn (string $principal) => match ($principal) {
			'principals/users/boss' => [['id' => 1, 'uri' => 'personal']],
			'principals/users/dev' => [['id' => 5, 'uri' => 'other'], ['id' => 2, 'uri' => 'personal']],
			default => [],
		});
		$backend->method('getCalendarObject')->willReturnCallback(static fn ($calendarId, string $uri) => match ([(int)$calendarId, $uri]) {
			[1, 'abc-123.ics'] => ['calendardata' => self::EVENT],
			[2, 'invite-xyz.ics'] => ['calendardata' => self::EVENT],
			default => null,
		});
		$backend->method('getCalendarObjectByUID')->willReturnCallback(static fn (string $principal, string $uid) => $principal === 'principals/users/dev' && $uid === 'abc-123'
			? 'personal/invite-xyz.ics'
			: null);
		return $backend;
	}

	private function service(CalDavBackend $backend): CalendarService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(CalDavBackend::class)->willReturn($backend);
		return new CalendarService($this->createMock(ICalendarManager::class), $container, new NullLogger());
	}

	public function testNeutralizeEventRewritesOrganizerAndAttendeeCopies(): void {
		$backend = $this->backend();
		$written = [];
		$backend->method('updateCalendarObject')->willReturnCallback(static function ($calendarId, string $uri, string $data) use (&$written) {
			$written[] = [(int)$calendarId, $uri, $data];
			return '';
		});

		$this->service($backend)->neutralizeEvent($this->organizer(), 'abc-123.ics', ['dev', 'qa', 'boss'], 'Interview: Backend Developer', '', null);

		$this->assertSame([[1, 'abc-123.ics'], [2, 'invite-xyz.ics']], array_map(static fn ($w) => [$w[0], $w[1]], $written));
		foreach ($written as [, , $data]) {
			$this->assertStringNotContainsString('Jane', $data);
		}
	}

	public function testCancelEventDeletesEveryCopyPermanentlyWhenAsked(): void {
		$backend = $this->backend();
		$deleted = [];
		$backend->method('deleteCalendarObject')->willReturnCallback(static function ($calendarId, string $uri, $type, bool $permanently) use (&$deleted) {
			$deleted[] = [(int)$calendarId, $uri, $permanently];
		});

		$this->service($backend)->cancelEvent($this->organizer(), 'abc-123.ics', ['dev'], permanently: true);
		$this->assertSame([[1, 'abc-123.ics', true], [2, 'invite-xyz.ics', true]], $deleted);
	}

	public function testAttendeeCopyIsFoundWhenTheOrganizerAlreadyDeletedTheirs(): void {
		$backend = $this->backend();
		$deleted = [];
		$backend->method('deleteCalendarObject')->willReturnCallback(static function ($calendarId, string $uri) use (&$deleted) {
			$deleted[] = [(int)$calendarId, $uri];
		});
		$organizer = $this->createMock(IUser::class);
		$organizer->method('getUID')->willReturn('someone-else');

		$this->service($backend)->cancelEvent($organizer, 'abc-123.ics', ['dev']);
		$this->assertSame([[2, 'invite-xyz.ics']], $deleted);
	}
}
