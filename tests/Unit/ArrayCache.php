<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCP\ICache;

/**
 * In-memory ICache for tests (TTLs are ignored).
 */
class ArrayCache implements ICache {
	/** @var array<string, mixed> */
	public array $values = [];

	#[\Override]
	public function get(string $key) {
		return $this->values[$key] ?? null;
	}

	#[\Override]
	public function set(string $key, $value, int $ttl = 0) {
		$this->values[$key] = $value;
		return true;
	}

	#[\Override]
	public function hasKey(string $key) {
		return array_key_exists($key, $this->values);
	}

	#[\Override]
	public function remove(string $key) {
		unset($this->values[$key]);
		return true;
	}

	#[\Override]
	public function clear(string $prefix = '') {
		foreach (array_keys($this->values) as $key) {
			if (str_starts_with($key, $prefix)) {
				unset($this->values[$key]);
			}
		}
		return true;
	}

	#[\Override]
	public static function isAvailable(): bool {
		return true;
	}
}
