<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Service\ConfigService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class TriageRetentionConfigTest extends TestCase {
	public function testDefaultsToNinetyDays(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')
			->with('recruiting', 'triage_retention_days', 90)
			->willReturnArgument(2);
		$this->assertSame(90, (new ConfigService($appConfig, $this->createMock(ICrypto::class)))->getTriageRetentionDays());
	}

	public function testValueIsClampedAndZeroDisables(): void {
		$stored = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueInt')->willReturnCallback(static function (string $app, string $key, int $value) use (&$stored) {
			$stored[] = $value;
			return true;
		});
		$config = new ConfigService($appConfig, $this->createMock(ICrypto::class));
		$config->setTriageRetentionDays(-5);
		$config->setTriageRetentionDays(0);
		$config->setTriageRetentionDays(99999);
		$this->assertSame([0, 0, 3650], $stored);
	}
}
