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
 * @extends QBMapper<Offer>
 */
class OfferMapper extends QBMapper {
	use ChunkedInQuery;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_offers', Offer::class);
	}

	public function find(int $id): Offer {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Offer[]
	 */
	public function findForCandidate(int $candidateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	public function hasActiveOffer(int $candidateId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(Offer::TERMINAL_STATES, IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Does this user have an offer of this candidate waiting for (or given)
	 * their approval? Grants the approver scoped visibility.
	 */
	public function isApproverForCandidate(int $candidateId, string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('approver_uid', $qb->createNamedParameter($uid)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Open offers (sent/negotiating) for the expiry job.
	 *
	 * @return Offer[]
	 */
	public function findOpen(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(Offer::OPEN_STATES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('valid_until'));
		return $this->findEntities($qb);
	}

	/**
	 * @return Offer[]
	 */
	public function findPendingForApprover(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('approver_uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Offer::STATUS_PENDING_APPROVAL)))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Open offer count per opening (for the reports view).
	 *
	 * @return array<int, int> openingId => count
	 */
	public function countOpenByOpening(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.opening_id')
			->addSelect($qb->func()->count('o.id', 'cnt'))
			->from($this->getTableName(), 'o')
			->innerJoin('o', 'recruiting_candidates', 'c', $qb->expr()->eq('c.id', 'o.candidate_id'))
			->where($qb->expr()->notIn('o.status', $qb->createNamedParameter(Offer::TERMINAL_STATES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('c.opening_id'))
			->groupBy('c.opening_id');
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['opening_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * The active (non-terminal) offer per candidate, for many candidates at
	 * once — the board's "needs attention" hints (no offer yet / expiring).
	 *
	 * @param int[] $candidateIds
	 * @return array<int, array{status: string, validUntil: ?string}>
	 */
	public function activeFor(array $candidateIds): array {
		$offers = [];
		foreach ($this->idChunks($candidateIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('candidate_id', 'status', 'valid_until')
				->from($this->getTableName())
				->where($qb->expr()->in('candidate_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(Offer::TERMINAL_STATES, IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$offers[(int)$row['candidate_id']] = [
					'status' => (string)$row['status'],
					'validUntil' => $row['valid_until'] !== null ? (string)$row['valid_until'] : null,
				];
			}
			$result->closeCursor();
		}
		return $offers;
	}

	public function deleteForCandidate(int $candidateId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
