<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\TodayService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class TodayController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private TodayService $today,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * What is waiting for the user today (the start page).
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->handle(fn () => $this->today->forUser($this->uid()));
	}
}
