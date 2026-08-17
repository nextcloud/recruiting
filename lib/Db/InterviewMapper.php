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
 * @extends QBMapper<Interview>
 */
class InterviewMapper extends QBMapper {
	use ChunkedInQuery;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_interviews', Interview::class);
	}

	public function find(int $id): Interview {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Atomically take the interview out of "proposed" — the public
	 * confirmation page has no session and can be submitted twice (double
	 * click, reload, two tabs), and each winner would otherwise create its
	 * own calendar event and Talk room.
	 *
	 * @return bool whether this caller won the race
	 */
	public function claimForConfirmation(int $id): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(Interview::STATUS_CONFIRMED))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Interview::STATUS_PROPOSED)));
		return $qb->executeStatement() === 1;
	}

	public function findByToken(string $token): ?Interview {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('public_token', $qb->createNamedParameter($token)));
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * @return Interview[]
	 */
	public function findForCandidate(int $candidateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Upcoming interviews where the user is an attendee.
	 *
	 * @return Interview[]
	 */
	public function findUpcomingForUser(string $uid, \DateTimeInterface $from): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.*')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'recruiting_intv_attendees', 'a', $qb->expr()->eq('a.interview_id', 'i.id'))
			->where($qb->expr()->eq('a.uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->neq('i.status', $qb->createNamedParameter(Interview::STATUS_CANCELLED)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('i.start_at'),
				$qb->expr()->gte('i.end_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
			))
			->orderBy('i.start_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Next confirmed interview per candidate (board cards) — one query
	 * instead of loading every interview of every candidate.
	 *
	 * @param int[] $candidateIds
	 * @return array<int, string> candidateId => ISO start time
	 */
	public function nextConfirmedFor(array $candidateIds, \DateTimeInterface $after): array {
		$next = [];
		foreach ($this->idChunks($candidateIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			// min() takes no alias (unlike count()), so alias it explicitly
			$qb->select('candidate_id')
				->selectAlias($qb->func()->min('start_at'), 'next_start')
				->from($this->getTableName())
				->where($qb->expr()->in('candidate_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Interview::STATUS_CONFIRMED)))
				->andWhere($qb->expr()->gt('start_at', $qb->createNamedParameter($after, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
				->groupBy('candidate_id');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$start = $row['next_start'] ?? null;
				if ($start === null) {
					continue;
				}
				try {
					$next[(int)$row['candidate_id']] = (new \DateTime((string)$start))->format(\DateTimeInterface::ATOM);
				} catch (\Exception) {
					// unparsable timestamp — omit rather than break the board
				}
			}
			$result->closeCursor();
		}
		return $next;
	}

	public function deleteForCandidate(int $candidateId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
