<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUserManager;

class OpeningService {
	public function __construct(
		private OpeningMapper $openingMapper,
		private TeamMemberMapper $teamMapper,
		private CandidateMapper $candidateMapper,
		private PermissionService $permissions,
		private TalkService $talk,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * All openings visible to the user, enriched with team, stage counts
	 * and the user's own role.
	 *
	 * @param string[]|null $onlyRoles restrict to these roles (null = any)
	 * @return array[]
	 */
	public function listForUser(string $uid, ?array $onlyRoles = null): array {
		$isRecruiter = $this->permissions->isRecruiter($uid);
		$memberships = [];
		foreach ($this->teamMapper->findForUser($uid) as $membership) {
			$memberships[$membership->getOpeningId()] = $membership->getRole();
		}

		$counts = $this->candidateMapper->countByOpeningAndStage();
		$teams = $this->teamMapper->findAllGrouped();
		$result = [];
		foreach ($this->openingMapper->findAll() as $opening) {
			$id = $opening->getId();
			$role = $isRecruiter ? PermissionService::ROLE_RECRUITER : ($memberships[$id] ?? null);
			if ($role === null || ($onlyRoles !== null && !in_array($role, $onlyRoles, true))) {
				continue;
			}
			$result[] = $this->serialize($opening, $role, $counts[$id] ?? [], $teams[$id] ?? []);
		}
		return $result;
	}

	public function get(string $uid, int $id): array {
		$this->permissions->assertCanViewOpening($uid, $id);
		$opening = $this->openingMapper->find($id);
		$counts = $this->candidateMapper->countByOpeningAndStage();
		return $this->serialize($opening, $this->permissions->roleForOpening($uid, $id), $counts[$id] ?? []);
	}

	/**
	 * @param array{title?: string, department?: string, location?: string, employmentType?: string,
	 *     description?: string, requirements?: string, autoAck?: bool, team?: array[]} $data
	 * @throws ValidationException
	 */
	public function create(string $uid, array $data): array {
		$this->permissions->assertRecruiter($uid);
		$title = trim((string)($data['title'] ?? ''));
		if ($title === '') {
			throw new ValidationException($this->l10n->t('A title is required'));
		}

		$opening = new Opening();
		$opening->setTitle($title);
		$opening->setDepartment(trim((string)($data['department'] ?? '')));
		$opening->setLocation(trim((string)($data['location'] ?? '')));
		$opening->setEmploymentType(trim((string)($data['employmentType'] ?? '')));
		$opening->setDescription((string)($data['description'] ?? ''));
		$opening->setRequirements((string)($data['requirements'] ?? ''));
		$opening->setAutoAck((bool)($data['autoAck'] ?? false));
		$opening->setMailSlug($this->uniqueSlug($title));
		$opening->setCreatedBy($uid);
		$opening->setCreatedAt($this->timeFactory->getDateTime());
		$opening = $this->openingMapper->insert($opening);

		$this->replaceTeam($opening->getId(), $data['team'] ?? []);
		return $this->get($uid, $opening->getId());
	}

	/**
	 * @throws ValidationException
	 */
	public function update(string $uid, int $id, array $data): array {
		$this->permissions->assertCanManageOpening($uid, $id);
		$opening = $this->openingMapper->find($id);

		if (array_key_exists('title', $data)) {
			$title = trim((string)$data['title']);
			if ($title === '') {
				throw new ValidationException($this->l10n->t('A title is required'));
			}
			$opening->setTitle($title);
		}
		foreach (['department' => 'setDepartment', 'location' => 'setLocation', 'employmentType' => 'setEmploymentType'] as $key => $setter) {
			if (array_key_exists($key, $data)) {
				$opening->$setter(trim((string)$data[$key]));
			}
		}
		foreach (['description' => 'setDescription', 'requirements' => 'setRequirements'] as $key => $setter) {
			if (array_key_exists($key, $data)) {
				$opening->$setter((string)$data[$key]);
			}
		}
		if (array_key_exists('autoAck', $data)) {
			$opening->setAutoAck((bool)$data['autoAck']);
		}
		$this->openingMapper->update($opening);

		if (array_key_exists('team', $data)) {
			$this->replaceTeam($id, is_array($data['team']) ? $data['team'] : []);
		}
		return $this->get($uid, $id);
	}

	/**
	 * @throws ValidationException
	 */
	public function setStatus(string $uid, int $id, string $status): array {
		$this->permissions->assertCanManageOpening($uid, $id);
		if (!in_array($status, [Opening::STATUS_OPEN, Opening::STATUS_ON_HOLD, Opening::STATUS_CLOSED], true)) {
			throw new ValidationException($this->l10n->t('Invalid status'));
		}
		$opening = $this->openingMapper->find($id);
		$opening->setStatus($status);
		$opening->setClosedAt($status === Opening::STATUS_CLOSED ? $this->timeFactory->getDateTime() : null);
		$this->openingMapper->update($opening);
		return $this->get($uid, $id);
	}

	/**
	 * Create the hiring-team Talk conversation for an opening (spec §4.10).
	 * The current team (plus the acting user) become members; changing the
	 * team later means recreating the room — the public Talk API cannot
	 * modify members afterwards.
	 *
	 * @throws ValidationException
	 */
	public function createTalkRoom(string $uid, int $id): array {
		$this->permissions->assertCanManageOpening($uid, $id);
		$opening = $this->openingMapper->find($id);
		if (!$this->talk->isAvailable()) {
			throw new ValidationException($this->l10n->t('Talk is not installed'));
		}
		if (($opening->getTalkToken() ?? '') !== '') {
			$this->talk->deleteRoom($opening->getTalkToken());
		}

		$members = [];
		foreach (array_unique(array_merge(
			[$uid],
			array_map(static fn (TeamMember $m) => $m->getUid(), $this->teamMapper->findForOpening($id)),
		)) as $memberUid) {
			$user = $this->userManager->get($memberUid);
			if ($user !== null) {
				$members[] = $user;
			}
		}
		$room = $this->talk->createTeamRoom(
			$this->l10n->t('Hiring: %s', [$opening->getTitle()]),
			$members,
		);
		if ($room === null) {
			throw new ValidationException($this->l10n->t('The Talk conversation could not be created'));
		}
		$opening->setTalkToken($room['token']);
		$this->openingMapper->update($opening);
		return $this->get($uid, $id);
	}

	/**
	 * @throws ValidationException
	 */
	public function removeTalkRoom(string $uid, int $id): array {
		$this->permissions->assertCanManageOpening($uid, $id);
		$opening = $this->openingMapper->find($id);
		if (($opening->getTalkToken() ?? '') !== '') {
			$this->talk->deleteRoom($opening->getTalkToken());
			$opening->setTalkToken(null);
			$this->openingMapper->update($opening);
		}
		return $this->get($uid, $id);
	}

	/**
	 * Replace the whole hiring team of an opening.
	 *
	 * @param array[] $team [{uid, role}]
	 * @throws ValidationException
	 */
	private function replaceTeam(int $openingId, array $team): void {
		$seen = [];
		$validated = [];
		foreach ($team as $member) {
			$uid = trim((string)($member['uid'] ?? ''));
			$role = (string)($member['role'] ?? '');
			if ($uid === '' || isset($seen[$uid])) {
				continue;
			}
			if (!in_array($role, TeamMember::ROLES, true)) {
				throw new ValidationException($this->l10n->t('Invalid team role'));
			}
			if ($this->userManager->get($uid) === null) {
				throw new ValidationException($this->l10n->t('Unknown user: %s', [$uid]));
			}
			$seen[$uid] = true;
			$validated[] = [$uid, $role];
		}

		$this->teamMapper->deleteForOpening($openingId);
		// Later checks in this request must see the new membership
		$this->permissions->clearRoleCache();
		foreach ($validated as [$uid, $role]) {
			$member = new TeamMember();
			$member->setOpeningId($openingId);
			$member->setUid($uid);
			$member->setRole($role);
			$this->teamMapper->insert($member);
		}
	}

	/**
	 * @param TeamMember[]|null $members pass to avoid a per-opening query
	 */
	private function serialize(Opening $opening, ?string $myRole, array $stageCounts, ?array $members = null): array {
		$team = array_map(
			fn (TeamMember $member) => [
				'uid' => $member->getUid(),
				'role' => $member->getRole(),
				'displayName' => $this->userManager->getDisplayName($member->getUid()) ?? $member->getUid(),
			],
			$members ?? $this->teamMapper->findForOpening($opening->getId()),
		);

		$counts = [];
		$active = 0;
		foreach (array_merge(Candidate::ACTIVE_STAGES, Candidate::TERMINAL_STAGES) as $stage) {
			$counts[$stage] = $stageCounts[$stage] ?? 0;
		}
		foreach (Candidate::ACTIVE_STAGES as $stage) {
			$active += $counts[$stage];
		}

		$talkToken = $opening->getTalkToken() ?? '';
		return array_merge($opening->jsonSerialize(), [
			'team' => $team,
			'myRole' => $myRole,
			'stageCounts' => $counts,
			'activeCount' => $active,
			'talkUrl' => $talkToken !== '' ? $this->talk->urlForToken($talkToken) : null,
		]);
	}

	private function uniqueSlug(string $title): string {
		$base = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
		$base = substr($base, 0, 40) ?: 'opening';
		$slug = $base;
		$i = 2;
		while ($this->openingMapper->mailSlugExists($slug)) {
			$slug = $base . '-' . $i;
			$i++;
		}
		return $slug;
	}
}
