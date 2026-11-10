<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Service\ReportCache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

class ReportCacheTest extends TestCase {
	private ArrayCache $store;

	private function cache(bool $distributed = true): ReportCache {
		$this->store = new ArrayCache();
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($distributed);
		$factory->expects($distributed ? $this->atMost(1) : $this->never())->method('createDistributed')
			->with('recruiting-reports')->willReturn($this->store);
		$factory->expects($distributed ? $this->never() : $this->atMost(1))->method('createLocal')
			->with('recruiting-reports')->willReturn($this->store);
		return new ReportCache($factory);
	}

	public function testStoredBlocksAreFound(): void {
		$cache = $this->cache();
		$miss = $cache->lookup(4, 'range');
		$this->assertNull($miss['block']);
		$cache->store($miss['key'], ['totalCount' => 3]);
		$this->assertSame(['totalCount' => 3], $cache->lookup(4, 'range')['block']);
		$this->assertNull($cache->lookup(4, 'other-range')['block']);
		$this->assertNull($cache->lookup(5, 'range')['block']);
	}

	public function testInvalidationDropsOneOpeningOrAll(): void {
		$cache = $this->cache();
		$cache->store($cache->lookup(4, 'r')['key'], ['a']);
		$cache->store($cache->lookup(5, 'r')['key'], ['b']);

		$cache->invalidate(4);
		$this->assertNull($cache->lookup(4, 'r')['block']);
		$this->assertSame(['b'], $cache->lookup(5, 'r')['block']);

		$cache->invalidate(null);
		$this->assertNull($cache->lookup(5, 'r')['block']);
	}

	public function testBlockComputedBeforeAChangeIsNeverServed(): void {
		$cache = $this->cache();
		$key = $cache->lookup(4, 'r')['key'];
		// a candidate changes while the report is being computed
		$cache->invalidate(4);
		$cache->store($key, ['stale']);
		$this->assertNull($cache->lookup(4, 'r')['block']);
	}

	public function testGenerationsNeverLookNumeric(): void {
		$cache = $this->cache();
		$cache->invalidate(4);
		$cache->lookup(4, 'r');
		foreach ($this->store->values as $key => $value) {
			if (str_starts_with($key, 'gen-')) {
				$this->assertIsString($value);
				$this->assertFalse(is_numeric($value));
			}
		}
	}

	public function testFallsBackToTheLocalCache(): void {
		$cache = $this->cache(false);
		$cache->store($cache->lookup(1, 'r')['key'], ['x']);
		$this->assertSame(['x'], $cache->lookup(1, 'r')['block']);
	}
}
