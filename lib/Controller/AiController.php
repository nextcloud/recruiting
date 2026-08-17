<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\AiService;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class AiController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private AiService $ai,
		private CandidateService $candidates,
		private PermissionService $permissions,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Schedule an AI task for a candidate; the client polls the result.
	 */
	#[NoAdminRequired]
	public function request(int $candidateId, string $action): DataResponse {
		return $this->handle(function () use ($candidateId, $action) {
			$uid = $this->uid();
			$candidate = $this->candidates->find($candidateId);
			if (in_array($action, AiService::MANAGE_ACTIONS, true)) {
				$this->permissions->assertCanManageCandidate($uid, $candidate);
			} elseif (in_array($action, AiService::VOTE_ACTIONS, true)) {
				if (!$this->permissions->canVote($uid, $candidate)) {
					throw new NotPermittedException('Not allowed');
				}
			} else {
				throw new ValidationException('Unknown AI action');
			}
			return ['taskId' => $this->ai->request($uid, $candidate, $action)];
		});
	}

	#[NoAdminRequired]
	public function taskResult(int $taskId): DataResponse {
		return $this->handle(fn () => $this->ai->taskResult($this->uid(), $taskId));
	}
}
