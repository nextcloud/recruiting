<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\BackgroundJob\InterviewReminderJob;
use OCA\Recruiting\Service\InterviewReminderService;
use OCA\Recruiting\Service\InterviewService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class InterviewReminderJobTest extends TestCase {
	public function testEveryStepRunsEvenWhenAnEarlierOneFails(): void {
		$reminders = $this->createMock(InterviewReminderService::class);
		$reminders->expects($this->once())->method('sendDue')->willThrowException(new \RuntimeException('SMTP down'));
		$reminders->expects($this->once())->method('sendNudges')->willThrowException(new \RuntimeException('SMTP down'));
		$interviews = $this->createMock(InterviewService::class);
		$interviews->expects($this->once())->method('markPastAsDone');

		$job = new InterviewReminderJob($this->createMock(ITimeFactory::class), $reminders, new NullLogger(), $interviews);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}
}
