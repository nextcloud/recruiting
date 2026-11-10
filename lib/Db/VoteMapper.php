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
 * @extends QBMapper<Vote>
 */
class VoteMapper extends QBMapper {
	use ChunkedInQuery;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_votes', Vote::class);
	}

	/**
	 * @return Vote[]
	 */
	public function findForCandidate(int $candidateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	public function findVote(int $candidateId, string $uid): ?Vote {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * The user's own votes among the given candidates — one query instead of
	 * one per candidate.
	 *
	 * @param int[] $candidateIds
	 * @return Vote[]
	 */
	public function findForUser(string $uid, array $candidateIds): array {
		$entities = [];
		foreach ($this->idChunks($candidateIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
				->andWhere($qb->expr()->in('candidate_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$entities = array_merge($entities, $this->findEntities($qb));
		}
		return $entities;
	}

	/**
	 * Vote tallies for many candidates at once (board view).
	 *
	 * @param int[] $candidateIds
	 * @return array<int, array<string, int>> candidateId => vote => count
	 */
	public function tallyFor(array $candidateIds): array {
		$tally = [];
		foreach ($this->idChunks($candidateIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('candidate_id', 'vote')
				->addSelect($qb->func()->count('id', 'cnt'))
				->from($this->getTableName())
				->where($qb->expr()->in('candidate_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->groupBy('candidate_id', 'vote');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$tally[(int)$row['candidate_id']][(string)$row['vote']] = (int)$row['cnt'];
			}
			$result->closeCursor();
		}
		return $tally;
	}

	/**
	 * Who has voted, for many candidates at once. Deliberately only the
	 * uids — the values stay behind the anti-anchoring rule (spec §4.3).
	 *
	 * @param int[] $candidateIds
	 * @return array<int, string[]> candidateId => uids that voted
	 */
	public function votedUidsFor(array $candidateIds): array {
		$uids = [];
		foreach ($this->idChunks($candidateIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('candidate_id', 'uid')
				->from($this->getTableName())
				->where($qb->expr()->in('candidate_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$uids[(int)$row['candidate_id']][] = (string)$row['uid'];
			}
			$result->closeCursor();
		}
		return $uids;
	}

	/**
	 * Anonymization keeps the vote values for statistics but wipes the
	 * written comments (spec §4.8).
	 */
	public function stripCommentsForCandidate(int $candidateId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('comment', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteForCandidate(int $candidateId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Move the votes of one candidate to another (merging duplicates).
	 * Where both have one by the same user, the target's is kept; the
	 * source's stays behind and goes when the source is deleted.
	 */
	public function moveToCandidate(int $fromId, int $toId): void {
		$kept = array_map(static fn (Vote $row) => $row->getUid(), $this->findForCandidate($toId));
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('candidate_id', $qb->createNamedParameter($toId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($fromId, IQueryBuilder::PARAM_INT)));
		if ($kept !== []) {
			$qb->andWhere($qb->expr()->notIn('uid', $qb->createNamedParameter($kept, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$qb->executeStatement();
	}
}
