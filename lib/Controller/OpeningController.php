<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\OpeningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class OpeningController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private OpeningService $openings,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->handle(fn () => $this->openings->listForUser($this->uid()));
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(fn () => $this->openings->get($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function create(): DataResponse {
		return $this->handle(fn () => $this->openings->create($this->uid(), $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse {
		return $this->handle(fn () => $this->openings->update($this->uid(), $id, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function setStatus(int $id, string $status): DataResponse {
		return $this->handle(fn () => $this->openings->setStatus($this->uid(), $id, $status));
	}

	#[NoAdminRequired]
	public function createTalkRoom(int $id): DataResponse {
		return $this->handle(fn () => $this->openings->createTalkRoom($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function removeTalkRoom(int $id): DataResponse {
		return $this->handle(fn () => $this->openings->removeTalkRoom($this->uid(), $id));
	}
}
