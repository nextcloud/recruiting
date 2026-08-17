<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TeamMember>
 */
class TeamMemberMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_team', TeamMember::class);
	}

	/**
	 * @return TeamMember[]
	 */
	public function findForOpening(int $openingId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('opening_id', $qb->createNamedParameter($openingId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Team members of every opening in one query — the openings list renders
	 * on every page load and must not cost a query per opening.
	 *
	 * @return array<int, TeamMember[]> openingId => members
	 */
	public function findAllGrouped(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'ASC');
		$grouped = [];
		foreach ($this->findEntities($qb) as $member) {
			$grouped[$member->getOpeningId()][] = $member;
		}
		return $grouped;
	}

	public function findMembership(int $openingId, string $uid): ?TeamMember {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('opening_id', $qb->createNamedParameter($openingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * All openings the user is a team member of.
	 *
	 * @return TeamMember[]
	 */
	public function findForUser(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return $this->findEntities($qb);
	}

	public function deleteForOpening(int $openingId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('opening_id', $qb->createNamedParameter($openingId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteForUser(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}
}
