<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Service\SlotFinderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\IAvailabilityResult;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class SlotFinderServiceTest extends TestCase {
	private ICalendarManager $calendarManager;
	private IUserManager $userManager;
	private SlotFinderService $finder;
	private IUser $organizer;

	protected function setUp(): void {
		$this->calendarManager = $this->createMock(ICalendarManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		// A Thursday, 10:23 UTC
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-08-13T10:23:00+00:00'));

		$this->organizer = $this->createMock(IUser::class);
		$this->organizer->method('getUID')->willReturn('boss');
		$this->organizer->method('getEMailAddress')->willReturn('boss@example.com');

		$interviewer = $this->createMock(IUser::class);
		$interviewer->method('getEMailAddress')->willReturn('dev@example.com');
		$this->userManager->method('get')->willReturnCallback(
			fn (string $uid) => $uid === 'boss' ? $this->organizer : ($uid === 'dev' ? $interviewer : null),
		);

		$this->finder = new SlotFinderService($this->calendarManager, $this->userManager, $timeFactory);
	}

	public function testAllFreeYieldsSlotsInsideBusinessHoursOnWeekdays(): void {
		$this->calendarManager->method('checkAvailability')->willReturn([]);

		$slots = $this->finder->propose($this->organizer, ['dev'], 60, 'UTC', 6);
		$this->assertCount(6, $slots);
		foreach ($slots as $slot) {
			$start = new \DateTimeImmutable($slot['start']);
			$end = new \DateTimeImmutable($slot['end']);
			$this->assertSame(3600, $end->getTimestamp() - $start->getTimestamp());
			$this->assertGreaterThanOrEqual(SlotFinderService::WORK_START_HOUR, (int)$start->format('G'));
			$this->assertLessThanOrEqual(SlotFinderService::WORK_END_HOUR, (int)$end->format('G'));
			$this->assertLessThan(6, (int)$start->format('N'), 'no weekend slots');
			$this->assertGreaterThan(new \DateTimeImmutable('2026-08-13T10:23:00+00:00'), $start);
		}
		// Thursday +1 = Friday 09:00 is the first candidate slot
		$this->assertSame('2026-08-14T09:00:00+00:00', (new \DateTimeImmutable($slots[0]['start']))->format('c'));
	}

	public function testBusyMorningsAreSkipped(): void {
		$result = $this->createMock(IAvailabilityResult::class);
		// Busy before 13:00, free afterwards
		$this->calendarManager->method('checkAvailability')->willReturnCallback(
			function (\DateTimeInterface $start) use ($result) {
				$busy = (int)$start->format('G') < 13;
				$result->method('isAvailable')->willReturn(!$busy);
				$mock = $this->createMock(IAvailabilityResult::class);
				$mock->method('isAvailable')->willReturn(!$busy);
				return [$mock];
			},
		);

		$slots = $this->finder->propose($this->organizer, ['dev'], 60, 'UTC', 3);
		$this->assertNotEmpty($slots);
		foreach ($slots as $slot) {
			$this->assertGreaterThanOrEqual(13, (int)(new \DateTimeImmutable($slot['start']))->format('G'));
		}
	}

	public function testWeekendStartRollsToMonday(): void {
		// "Now" is Thursday; a Friday start means +1 day = Friday. To test the
		// weekend roll we ask for slots when everything Friday is busy.
		$this->calendarManager->method('checkAvailability')->willReturnCallback(
			function (\DateTimeInterface $start) {
				$isFriday = (int)$start->format('N') === 5;
				$mock = $this->createMock(IAvailabilityResult::class);
				$mock->method('isAvailable')->willReturn(!$isFriday);
				return [$mock];
			},
		);

		$slots = $this->finder->propose($this->organizer, ['dev'], 30, 'UTC', 2);
		$this->assertNotEmpty($slots);
		// First free slot must be Monday (2026-08-17), never Saturday/Sunday
		$first = new \DateTimeImmutable($slots[0]['start']);
		$this->assertSame('2026-08-17', $first->format('Y-m-d'));
	}

	public function testDurationIsClamped(): void {
		$this->calendarManager->method('checkAvailability')->willReturn([]);
		$slots = $this->finder->propose($this->organizer, [], 5, 'UTC', 1);
		$start = new \DateTimeImmutable($slots[0]['start']);
		$end = new \DateTimeImmutable($slots[0]['end']);
		$this->assertSame(15 * 60, $end->getTimestamp() - $start->getTimestamp());
	}

	public function testCalendarErrorsDoNotBlockScheduling(): void {
		$this->calendarManager->method('checkAvailability')->willThrowException(new \RuntimeException('CalDAV down'));
		$slots = $this->finder->propose($this->organizer, ['dev'], 60, 'UTC', 2);
		$this->assertCount(2, $slots);
	}
}
