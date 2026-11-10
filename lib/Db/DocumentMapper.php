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
 * @extends QBMapper<Document>
 */
class DocumentMapper extends QBMapper {
	use ChunkedInQuery;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_documents', Document::class);
	}

	public function find(int $id): Document {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Document[]
	 */
	public function findForCandidate(int $candidateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Document counts for many candidates at once (board/list rendering).
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
	 * The most recent document of a candidate that carries extracted text —
	 * the CV the AI material uses.
	 */
	public function findLatestWithText(int $candidateId): ?Document {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('text_content'))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Candidates with a document whose extracted text contains the term
	 * (case-insensitive), newest documents first.
	 *
	 * @return int[]
	 */
	public function findCandidateIdsByText(string $term, int $limit): array {
		$term = trim($term);
		if ($term === '' || $limit <= 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('candidate_id')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('text_content'))
			->andWhere($qb->expr()->iLike('text_content', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%')))
			->groupBy('candidate_id')
			->orderBy($qb->func()->max('id'), 'DESC')
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		$ids = [];
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int)$id;
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Documents of the given types that have no extracted text yet, in id
	 * order after $afterId (for the back-fill).
	 *
	 * @param string[] $mimes
	 * @return Document[]
	 */
	public function findWithoutText(array $mimes, int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('text_content'))
			->andWhere($qb->expr()->in('mime', $qb->createNamedParameter($mimes, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function deleteForCandidate(int $candidateId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
