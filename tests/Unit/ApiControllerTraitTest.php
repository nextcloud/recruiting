<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Controller\ApiControllerTrait;
use OCA\Recruiting\Exception\ConflictException;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\TestCase;

/**
 * Domain exceptions become clean JSON errors with the right status code.
 */
class ApiControllerTraitTest extends TestCase {
	private function controller(): object {
		return new class {
			use ApiControllerTrait;

			public function run(\Closure $fn): DataResponse {
				return $this->handle($fn);
			}
		};
	}

	public function testConflictBecomes409WithTheCurrentState(): void {
		$response = $this->controller()->run(static function (): never {
			throw new ConflictException('Moved by someone else', ['stage' => 'offer', 'displayName' => 'Ada']);
		});

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['stage' => 'offer', 'displayName' => 'Ada', 'message' => 'Moved by someone else'], $response->getData());
	}

	public function testConflictDataCannotOverrideTheMessage(): void {
		$response = $this->controller()->run(static function (): never {
			throw new ConflictException('Moved by someone else', ['message' => 'spoofed']);
		});

		$this->assertSame('Moved by someone else', $response->getData()['message']);
	}

	public function testValidationAndPermissionErrorsKeepTheirCodes(): void {
		$controller = $this->controller();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->run(static function (): never {
			throw new ValidationException('bad');
		})->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->run(static function (): never {
			throw new NotPermittedException('no');
		})->getStatus());
	}
}
