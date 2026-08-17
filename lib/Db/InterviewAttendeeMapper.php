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
 * @extends QBMapper<InterviewAttendee>
 */
class InterviewAttendeeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_intv_attendees', InterviewAttendee::class);
	}

	/**
	 * @return InterviewAttendee[]
	 */
	public function findForInterview(int $interviewId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('interview_id', $qb->createNamedParameter($interviewId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function isAttendee(int $interviewId, string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('interview_id', $qb->createNamedParameter($interviewId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Candidate ids the user can see because they interview them.
	 *
	 * @return int[]
	 */
	public function findCandidateIdsForUser(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('i.candidate_id')
			->from($this->getTableName(), 'a')
			->innerJoin('a', 'recruiting_interviews', 'i', $qb->expr()->eq('i.id', 'a.interview_id'))
			->where($qb->expr()->eq('a.uid', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$ids = array_map(static fn ($row) => (int)$row['candidate_id'], $result->fetchAll());
		$result->closeCursor();
		return $ids;
	}

	public function deleteForUser(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}

	public function deleteForInterview(int $interviewId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('interview_id', $qb->createNamedParameter($interviewId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
