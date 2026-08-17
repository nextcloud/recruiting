<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * User autocomplete for the team / interviewer / screener pickers.
 *
 * Restricted to people who actually staff a hiring process — otherwise this
 * would be an account-enumeration endpoint for every user on the instance.
 */
class UserController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserManager $userManager,
		private PermissionService $permissions,
		private TeamMemberMapper $teamMapper,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function search(string $query = ''): DataResponse {
		return $this->handle(function () use ($query) {
			$this->assertMayPickPeople();
			$query = trim($query);
			if (mb_strlen($query) < 2) {
				return [];
			}
			$results = [];
			foreach ($this->userManager->searchDisplayName($query, 20) as $user) {
				if (!$user->isEnabled()) {
					continue;
				}
				$results[] = [
					'uid' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
				];
			}
			return $results;
		});
	}

	/**
	 * Recruiters and hiring managers assemble teams, pick screeners,
	 * interviewers and offer approvers — nobody else needs the directory.
	 *
	 * @throws NotPermittedException
	 */
	private function assertMayPickPeople(): void {
		$uid = $this->uid();
		if ($uid === '') {
			throw new NotPermittedException('No session user');
		}
		if ($this->permissions->isRecruiter($uid)) {
			return;
		}
		foreach ($this->teamMapper->findForUser($uid) as $membership) {
			if ($membership->getRole() === TeamMember::ROLE_MANAGER) {
				return;
			}
		}
		throw new NotPermittedException('Only recruiters and hiring managers may search for people');
	}
}
