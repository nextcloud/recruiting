<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Template management is recruiter-only (spec §4.5).
 */
class TemplateController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private TemplateService $templates,
		private PermissionService $permissions,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->handle(function () {
			$this->permissions->assertRecruiter($this->uid());
			return $this->templates->listAll();
		});
	}

	#[NoAdminRequired]
	public function create(): DataResponse {
		return $this->handle(function () {
			$this->permissions->assertRecruiter($this->uid());
			return $this->templates->create($this->request->getParams())->jsonSerialize();
		});
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->permissions->assertRecruiter($this->uid());
			return $this->templates->update($id, $this->request->getParams())->jsonSerialize();
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->permissions->assertRecruiter($this->uid());
			$this->templates->delete($id);
			return ['deleted' => true];
		});
	}
}
