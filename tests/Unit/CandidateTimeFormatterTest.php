<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Service\CandidateTimeFormatter;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class CandidateTimeFormatterTest extends TestCase {
	private function formatter(string $timezone): CandidateTimeFormatter {
		$config = $this->createMock(IUserConfig::class);
		$config->method('getValueString')->willReturn($timezone);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('l')->willReturnCallback(
			static fn (string $type, \DateTimeInterface $at) => $at->format($type === 'date' ? 'Y-m-d' : 'H:i'),
		);
		return new CandidateTimeFormatter($config, $l10n);
	}

	public function testTimesAreShownInTheOrganizersTimezoneWithItsName(): void {
		$result = $this->formatter('Europe/Berlin')->format(new \DateTime('2026-11-11T23:30:00Z'), 'boss');
		$this->assertSame(['date' => '2026-11-12', 'time' => '00:30 (Europe/Berlin)'], $result);
	}

	public function testAnInvalidTimezoneFallsBackSafely(): void {
		$result = $this->formatter('Mars/Olympus')->format(new \DateTime('2026-11-11T10:00:00Z'), 'boss');
		$this->assertStringContainsString('(', $result['time']);
	}
}
