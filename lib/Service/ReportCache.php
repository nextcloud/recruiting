<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Per-opening report statistics, cached for ten minutes and shared by all
 * users (the per-user overview is composed from these blocks).
 *
 * Invalidation bumps a generation counter instead of deleting keys: a
 * report computed from data read before the change is stored under the
 * old generation and never read again, so no request can resurrect a
 * stale block.
 */
class ReportCache {
	public const TTL = 600;
	private const GENERATION_TTL = 7 * 24 * 3600;
	private const GLOBAL = 'all';

	private ?ICache $cache = null;

	public function __construct(
		private ICacheFactory $cacheFactory,
	) {
	}

	/**
	 * The cached block of an opening plus the key to store a freshly
	 * computed one under. The key is taken before the data is read, so a
	 * block computed while a change lands goes to the outdated generation.
	 *
	 * @return array{key: string, block: ?array}
	 */
	public function lookup(int $openingId, string $rangeKey): array {
		$key = 'block-' . $openingId . '-' . $this->generation(self::GLOBAL)
			. '-' . $this->generation((string)$openingId) . '-' . $rangeKey;
		$value = $this->cache()->get($key);
		return ['key' => $key, 'block' => is_array($value) ? $value : null];
	}

	public function store(string $key, array $block): void {
		$this->cache()->set($key, $block, self::TTL);
	}

	/**
	 * Drop the cached statistics of one opening, or of every opening when
	 * null (a candidate moved between openings).
	 */
	public function invalidate(?int $openingId): void {
		$this->cache()->set('gen-' . ($openingId ?? self::GLOBAL), $this->newGeneration(), self::GENERATION_TTL);
	}

	private function generation(string $name): string {
		$value = $this->cache()->get('gen-' . $name);
		if (is_string($value) && $value !== '') {
			return $value;
		}
		// A lost counter must not fall back to a value an older block
		// was stored under
		$value = $this->newGeneration();
		$this->cache()->set('gen-' . $name, $value, self::GENERATION_TTL);
		return $value;
	}

	/**
	 * Prefixed so it never looks numeric: some cache backends hand numeric
	 * strings back as numbers.
	 */
	private function newGeneration(): string {
		return 'g' . bin2hex(random_bytes(6));
	}

	private function cache(): ICache {
		if ($this->cache === null) {
			$this->cache = $this->cacheFactory->isAvailable()
				? $this->cacheFactory->createDistributed('recruiting-reports')
				: $this->cacheFactory->createLocal('recruiting-reports');
		}
		return $this->cache;
	}
}
