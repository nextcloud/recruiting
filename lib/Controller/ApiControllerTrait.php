<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;

/**
 * Shared error handling: turns domain exceptions into clean JSON error
 * responses and never leaks internal exception text to the client.
 */
trait ApiControllerTrait {
	/**
	 * The acting user. Controllers using this trait must have a
	 * `$userSession` constructor property.
	 */
	protected function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/**
	 * @param \Closure():mixed $fn
	 */
	protected function handle(\Closure $fn): DataResponse {
		try {
			return new DataResponse($fn());
		} catch (\Throwable $e) {
			return $this->mapError($e);
		}
	}

	/**
	 * Same mapping for endpoints that return something other than JSON
	 * (file downloads) — so a storage failure surfaces as a logged 500
	 * instead of a silent "not found".
	 *
	 * @param \Closure():Response $fn
	 */
	protected function handleResponse(\Closure $fn): Response {
		try {
			return $fn();
		} catch (\Throwable $e) {
			return $this->mapError($e);
		}
	}

	private function mapError(\Throwable $e): DataResponse {
		if ($e instanceof ValidationException) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		if ($e instanceof NotPermittedException) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
		if ($e instanceof DoesNotExistException) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		\OCP\Server::get(\Psr\Log\LoggerInterface::class)->error(
			'Recruiting: unhandled error in API request',
			['exception' => $e, 'app' => 'recruiting'],
		);
		return new DataResponse(['message' => 'An unexpected error occurred.'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
