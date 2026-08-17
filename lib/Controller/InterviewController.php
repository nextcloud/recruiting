<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\InterviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class InterviewController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private InterviewService $interviews,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param string[] $attendees
	 */
	#[NoAdminRequired]
	public function proposeSlots(int $candidateId, array $attendees = [], int $durationMin = 60, string $timezone = 'UTC'): DataResponse {
		return $this->handle(fn () => $this->interviews->proposeSlots($this->uid(), $candidateId, $attendees, $durationMin, $timezone));
	}

	#[NoAdminRequired]
	public function create(int $candidateId): DataResponse {
		return $this->handle(fn () => $this->interviews->create($this->uid(), $candidateId, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function sendInvite(int $id, string $subject, string $body): DataResponse {
		return $this->handle(fn () => $this->interviews->sendInvite($this->uid(), $id, $subject, $body));
	}

	#[NoAdminRequired]
	public function cancel(int $id): DataResponse {
		return $this->handle(fn () => $this->interviews->cancel($this->uid(), $id));
	}
}
