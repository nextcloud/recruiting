<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\ScreeningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ScreeningController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ScreeningService $screening,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param string[] $uids
	 */
	#[NoAdminRequired]
	public function assign(int $candidateId, array $uids = []): DataResponse {
		return $this->handle(function () use ($candidateId, $uids) {
			$this->screening->assign($this->uid(), $candidateId, $uids);
			return ['assigned' => true];
		});
	}

	#[NoAdminRequired]
	public function unassign(int $candidateId, string $uid): DataResponse {
		return $this->handle(function () use ($candidateId, $uid) {
			$this->screening->unassign($this->uid(), $candidateId, $uid);
			return ['unassigned' => true];
		});
	}

	#[NoAdminRequired]
	public function vote(int $candidateId, string $vote, string $comment = ''): DataResponse {
		return $this->handle(fn () => $this->screening->vote($this->uid(), $candidateId, $vote, $comment)->jsonSerialize());
	}
}
