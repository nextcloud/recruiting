<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Exception;

/**
 * Thrown when a change was based on state that someone else has changed in
 * the meantime. Controllers translate this into a 409 response carrying the
 * (already translated) message and the current state from getData().
 */
class ConflictException extends \Exception {
	/**
	 * @param array<string, mixed> $data current state the client needs to resolve the conflict
	 */
	public function __construct(
		string $message,
		private array $data = [],
	) {
		parent::__construct($message);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getData(): array {
		return $this->data;
	}
}
