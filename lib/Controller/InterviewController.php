<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\InterviewFeedbackService;
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
		private InterviewFeedbackService $feedback,
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
	public function cancel(int $id, ?string $mailSubject = null, ?string $mailBody = null): DataResponse {
		return $this->handle(fn () => $this->interviews->cancel($this->uid(), $id, $mailSubject, $mailBody));
	}

	#[NoAdminRequired]
	public function cancelPreview(int $id): DataResponse {
		return $this->handle(fn () => $this->interviews->cancelPreview($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function reschedule(int $id): DataResponse {
		return $this->handle(fn () => $this->interviews->reschedule($this->uid(), $id, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function feedback(int $id, string $verdict, string $notes = ''): DataResponse {
		return $this->handle(fn () => $this->feedback->submit($this->uid(), $id, $verdict, $notes));
	}

	#[NoAdminRequired]
	public function feedbackOwed(): DataResponse {
		return $this->handle(fn () => $this->feedback->owed($this->uid()));
	}
}
