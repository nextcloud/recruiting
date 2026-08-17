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
 * @extends QBMapper<InterviewSlot>
 */
class InterviewSlotMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_intv_slots', InterviewSlot::class);
	}

	public function find(int $id): InterviewSlot {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return InterviewSlot[]
	 */
	public function findForInterview(int $interviewId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('interview_id', $qb->createNamedParameter($interviewId, IQueryBuilder::PARAM_INT)))
			->orderBy('start_at', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForInterview(int $interviewId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('interview_id', $qb->createNamedParameter($interviewId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
