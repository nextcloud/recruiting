<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Votes, screeners, interviews, mail and stage moves all leave a timeline
 * event, so recording one counts up the board revision of the candidate's
 * opening.
 *
 * @extends QBMapper<TimelineEvent>
 */
class TimelineEventMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private OpeningMapper $openingMapper,
	) {
		parent::__construct($db, 'recruiting_events', TimelineEvent::class);
	}

	/**
	 * @param TimelineEvent $entity
	 * @return TimelineEvent
	 */
	#[\Override]
	public function insert(Entity $entity): Entity {
		$entity = parent::insert($entity);
		$this->openingMapper->bumpRevisionForCandidate($entity->getCandidateId());
		return $entity;
	}

	/**
	 * @return TimelineEvent[]
	 */
	public function findForCandidate(int $candidateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForCandidate(int $candidateId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
