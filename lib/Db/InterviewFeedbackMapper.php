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
 * @extends QBMapper<InterviewFeedback>
 */
class InterviewFeedbackMapper extends QBMapper {
	use ChunkedInQuery;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_intv_feedback', InterviewFeedback::class);
	}

	/**
	 * @param int[] $interviewIds
	 * @return InterviewFeedback[]
	 */
	public function findForInterviews(array $interviewIds): array {
		$entities = [];
		foreach ($this->idChunks($interviewIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('interview_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id', 'ASC');
			$entities = array_merge($entities, $this->findEntities($qb));
		}
		return $entities;
	}

	public function findFeedback(int $interviewId, string $uid): ?InterviewFeedback {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('interview_id', $qb->createNamedParameter($interviewId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Anonymization keeps the verdicts for statistics but wipes the notes.
	 */
	public function stripNotesForCandidate(int $candidateId): void {
		foreach ($this->idChunks($this->interviewIdsFor($candidateId)) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('notes', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($qb->expr()->in('interview_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function deleteForCandidate(int $candidateId): void {
		foreach ($this->idChunks($this->interviewIdsFor($candidateId)) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('interview_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * @return int[]
	 */
	private function interviewIdsFor(int $candidateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('recruiting_interviews')
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map(static fn ($row) => (int)$row['id'], $result->fetchAll());
		$result->closeCursor();
		return $ids;
	}
}
