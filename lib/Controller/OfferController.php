<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\OfferService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class OfferController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private OfferService $offers,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function create(int $candidateId): DataResponse {
		return $this->handle(fn () => $this->offers->create($this->uid(), $candidateId, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse {
		return $this->handle(fn () => $this->offers->update($this->uid(), $id, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function submit(int $id, string $approverUid): DataResponse {
		return $this->handle(fn () => $this->offers->submit($this->uid(), $id, $approverUid));
	}

	#[NoAdminRequired]
	public function approve(int $id): DataResponse {
		return $this->handle(fn () => $this->offers->approve($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function declineApproval(int $id, string $note = ''): DataResponse {
		return $this->handle(fn () => $this->offers->declineApproval($this->uid(), $id, $note));
	}

	#[NoAdminRequired]
	public function send(int $id, ?string $mailSubject = null, ?string $mailBody = null): DataResponse {
		return $this->handle(fn () => $this->offers->send($this->uid(), $id, $mailSubject, $mailBody));
	}

	#[NoAdminRequired]
	public function respond(int $id, string $response): DataResponse {
		return $this->handle(fn () => $this->offers->respond($this->uid(), $id, $response));
	}

	#[NoAdminRequired]
	public function withdraw(int $id): DataResponse {
		return $this->handle(fn () => $this->offers->withdraw($this->uid(), $id));
	}
}
