<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Service\AutoAckGuard;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The daily cap on automatic acknowledgements per opening.
 */
class AutoAckGuardTest extends TestCase {
	private ConfigService&MockObject $config;
	private NotificationService&MockObject $notifications;
	private int $cap = 2;
	private string $today = '2026-11-10';
	/** @var array<int, array{date: string, count: int, notified: bool}> */
	private array $counters = [];
	private AutoAckGuard $guard;

	protected function setUp(): void {
		$this->config = $this->createMock(ConfigService::class);
		$this->config->method('getAutoAckDailyCap')->willReturnCallback(fn () => $this->cap);
		$this->config->method('getHrGroup')->willReturn('recruiting');
		$this->config->method('getAutoAckCounter')->willReturnCallback(
			fn (int $id) => $this->counters[$id] ?? ['date' => '', 'count' => 0, 'notified' => false],
		);
		$this->config->method('setAutoAckCounter')->willReturnCallback(
			function (int $id, array $counter): void {
				$this->counters[$id] = $counter;
			},
		);

		$this->notifications = $this->createMock(NotificationService::class);

		$users = [];
		foreach (['hr1', 'hr2'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('recruiting')->willReturn($group);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn () => new \DateTime($this->today . 'T15:00:00+00:00'));

		$this->guard = new AutoAckGuard($this->config, $this->notifications, $groupManager, $time, new NullLogger());
	}

	private function opening(int $id = 7): Opening {
		$opening = new Opening();
		$opening->setId($id);
		$opening->setTitle('Backend Engineer');
		return $opening;
	}

	public function testAcknowledgementsUpToTheCapAreAllowed(): void {
		$this->notifications->expects($this->never())->method('notifyAutoAckCapReached');

		$this->assertTrue($this->guard->tryAcquire($this->opening()));
		$this->assertTrue($this->guard->tryAcquire($this->opening()));
		$this->assertSame(['date' => '2026-11-10', 'count' => 2, 'notified' => false], $this->counters[7]);
	}

	public function testBeyondTheCapRecruitersAreNotifiedOnceADay(): void {
		$this->counters[7] = ['date' => '2026-11-10', 'count' => 2, 'notified' => false];
		$this->notifications->expects($this->once())->method('notifyAutoAckCapReached')
			->with(['hr1', 'hr2'], $this->isInstanceOf(Opening::class), 2);

		$this->assertFalse($this->guard->tryAcquire($this->opening()));
		$this->assertFalse($this->guard->tryAcquire($this->opening()));
		$this->assertFalse($this->guard->tryAcquire($this->opening()));
		$this->assertTrue($this->counters[7]['notified']);
		$this->assertSame(2, $this->counters[7]['count']);
	}

	public function testTheCountResetsOnTheNextDay(): void {
		$this->counters[7] = ['date' => '2026-11-09', 'count' => 2, 'notified' => true];

		$this->assertTrue($this->guard->tryAcquire($this->opening()));
		$this->assertSame(['date' => '2026-11-10', 'count' => 1, 'notified' => false], $this->counters[7]);
	}

	public function testOpeningsAreCountedSeparately(): void {
		$this->counters[7] = ['date' => '2026-11-10', 'count' => 2, 'notified' => true];

		$this->assertFalse($this->guard->tryAcquire($this->opening(7)));
		$this->assertTrue($this->guard->tryAcquire($this->opening(8)));
	}

	public function testZeroMeansUnlimitedAndCountsNothing(): void {
		$this->cap = 0;
		$this->counters[7] = ['date' => '2026-11-10', 'count' => 5000, 'notified' => false];
		$this->config->expects($this->never())->method('setAutoAckCounter');

		$this->assertTrue($this->guard->tryAcquire($this->opening()));
	}

	public function testAMissingHrGroupStillBlocksAndNotifiesNobody(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-10T15:00:00+00:00'));
		$guard = new AutoAckGuard($this->config, $this->notifications, $groupManager, $time, new NullLogger());
		$this->counters[7] = ['date' => '2026-11-10', 'count' => 2, 'notified' => false];
		$this->notifications->expects($this->once())->method('notifyAutoAckCapReached')->with([]);

		$this->assertFalse($guard->tryAcquire($this->opening()));
	}
}
