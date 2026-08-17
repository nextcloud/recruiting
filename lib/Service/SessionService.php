<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCP\IUserSession;

/**
 * Bootstrap payload for the SPA.
 */
class SessionService {
	public function __construct(
		private IUserSession $userSession,
		private PermissionService $permissions,
		private CandidateMapper $candidateMapper,
		private TalkService $talk,
		private AiService $ai,
	) {
	}

	public function getSessionInfo(): array {
		$user = $this->userSession->getUser();
		$uid = $user?->getUID() ?? '';
		$isRecruiter = $uid !== '' && $this->permissions->isRecruiter($uid);
		return [
			'uid' => $uid,
			'displayName' => $user?->getDisplayName() ?? '',
			'isRecruiter' => $isRecruiter,
			'talkAvailable' => $this->talk->isAvailable(),
			'aiAvailable' => $this->ai->isAvailable(),
			'triageCount' => $isRecruiter ? $this->candidateMapper->countTriage() : 0,
			'stages' => Candidate::ACTIVE_STAGES,
			'terminalStages' => Candidate::TERMINAL_STAGES,
			'rejectionReasons' => Candidate::REJECTION_REASONS,
		];
	}
}
