<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\BackgroundJob\RetentionJob;
use OCA\Recruiting\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetentionJobTest extends TestCase {
	private RetentionService&MockObject $retention;
	private LoggerInterface&MockObject $logger;
	private RetentionJob $job;

	protected function setUp(): void {
		$this->retention = $this->createMock(RetentionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->job = new RetentionJob($this->createMock(ITimeFactory::class), $this->retention, $this->logger);
	}

	private function runJob(): void {
		$run = new \ReflectionMethod($this->job, 'run');
		$run->invoke($this->job, null);
	}

	public function testRunsHourlyAndIsNotTimeSensitive(): void {
		$this->assertSame(3600, $this->job->getInterval());
		$this->assertFalse($this->job->isTimeSensitive());
	}

	public function testEachRunHandlesOneBatch(): void {
		$this->retention->expects($this->once())->method('run')->with()
			->willReturn(['anonymized' => 150, 'deleted' => 50, 'complete' => false, 'groups' => []]);
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('follow in the next run'));

		$this->runJob();
	}

	public function testQuietWhenNothingIsDue(): void {
		$this->retention->method('run')->willReturn(['anonymized' => 0, 'deleted' => 0, 'complete' => true, 'groups' => []]);
		$this->logger->expects($this->never())->method('info');

		$this->runJob();
	}
}
