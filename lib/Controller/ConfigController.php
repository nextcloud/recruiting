<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\SessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ConfigController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private SessionService $sessionService,
		private \OCA\Recruiting\Service\DigestService $digest,
		private \OCA\Recruiting\Service\ConfigService $config,
		private \OCA\Recruiting\Service\PermissionService $permissions,
		private \OCP\IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function session(): DataResponse {
		return $this->handle(fn () => $this->sessionService->getSessionInfo());
	}

	#[NoAdminRequired]
	public function ingestionStatus(): DataResponse {
		return $this->handle(function () {
			$this->permissions->assertRecruiter($this->uid());
			return [
				'enabled' => $this->config->isImapEnabled(),
				'status' => $this->config->getIngestionStatus(),
			];
		});
	}

	#[NoAdminRequired]
	public function getPersonal(): DataResponse {
		return $this->handle(fn () => ['digest' => $this->digest->getMode($this->uid())]);
	}

	#[NoAdminRequired]
	public function updatePersonal(string $digest): DataResponse {
		return $this->handle(function () use ($digest) {
			$this->digest->setMode($this->uid(), $digest);
			return ['digest' => $this->digest->getMode($this->uid())];
		});
	}
}
