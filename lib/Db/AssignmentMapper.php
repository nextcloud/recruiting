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
 * @extends QBMapper<Assignment>
 */
class AssignmentMapper extends QBMapper {
	use ChunkedInQuery;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_assignments', Assignment::class);
	}

	/**
	 * @return Assignment[]
	 */
	public function findForCandidate(int $candidateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findAssignment(int $candidateId, string $uid): ?Assignment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Candidate ids the user is assigned to as screener.
	 *
	 * @return int[]
	 */
	public function findCandidateIdsForUser(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('candidate_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$ids = array_map(static fn ($row) => (int)$row['candidate_id'], $result->fetchAll());
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Screener counts for many candidates at once (board/list rendering).
	 *
	 * @param int[] $candidateIds
	 * @return array<int, int> candidateId => count
	 */
	public function countFor(array $candidateIds): array {
		$counts = [];
		foreach ($this->idChunks($candidateIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('candidate_id')
				->addSelect($qb->func()->count('id', 'cnt'))
				->from($this->getTableName())
				->where($qb->expr()->in('candidate_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->groupBy('candidate_id');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$counts[(int)$row['candidate_id']] = (int)$row['cnt'];
			}
			$result->closeCursor();
		}
		return $counts;
	}

	/**
	 * Which of these candidates has the user already been assigned to?
	 *
	 * @param int[] $candidateIds
	 * @return int[]
	 */
	public function filterAssignedForUser(array $candidateIds, string $uid): array {
		if ($candidateIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('candidate_id')
			->from($this->getTableName())
			->where($qb->expr()->in('candidate_id', $qb->createNamedParameter($candidateIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$ids = array_map(static fn ($row) => (int)$row['candidate_id'], $result->fetchAll());
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Screener uids for many candidates at once (board avatar piles).
	 *
	 * @param int[] $candidateIds
	 * @return array<int, string[]> candidateId => uids
	 */
	public function uidsFor(array $candidateIds): array {
		$uids = [];
		foreach ($this->idChunks($candidateIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('candidate_id', 'uid')
				->from($this->getTableName())
				->where($qb->expr()->in('candidate_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('created_at', 'ASC')
				->addOrderBy('id', 'ASC');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$uids[(int)$row['candidate_id']][] = (string)$row['uid'];
			}
			$result->closeCursor();
		}
		return $uids;
	}

	public function deleteForCandidate(int $candidateId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteForUser(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}
}
