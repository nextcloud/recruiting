<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class TimelineServiceTest extends TestCase {
	public function testInvalidUtf8InTheDataNeverFailsTheEntry(): void {
		$mapper = $this->createMock(TimelineEventMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-11T10:00:00Z'));

		$event = (new TimelineService($mapper, $time))->record(5, TimelineEvent::TYPE_MAIL_RECEIVED, null, ['subject' => "Bewerbung f\xFCr"]);

		$this->assertSame(['subject' => "Bewerbung f\u{FFFD}r"], $event->getDecodedData());
	}
}
