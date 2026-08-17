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
 * @extends QBMapper<Candidate>
 */
class CandidateMapper extends QBMapper {
	use ChunkedInQuery;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_candidates', Candidate::class);
	}

	public function find(int $id): Candidate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Candidate[]
	 */
	public function findForOpening(int $openingId, ?string $stage = null, int $limit = 0, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('opening_id', $qb->createNamedParameter($openingId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');
		if ($stage !== null) {
			$qb->andWhere($qb->expr()->eq('stage', $qb->createNamedParameter($stage)));
		}
		if ($limit > 0) {
			$qb->setMaxResults($limit)->setFirstResult($offset);
		}
		return $this->findEntities($qb);
	}

	/**
	 * Candidates without an opening: the triage inbox.
	 *
	 * @return Candidate[]
	 */
	public function findTriage(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('opening_id'))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function countTriage(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->isNull('opening_id'));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @param int[] $ids
	 * @return Candidate[]
	 */
	public function findByIds(array $ids): array {
		$entities = [];
		foreach ($this->idChunks($ids) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$entities = array_merge($entities, $this->findEntities($qb));
		}
		return $entities;
	}

	/**
	 * Duplicate detection: same address, same opening, applied recently.
	 */
	public function findRecentByEmail(string $email, ?int $openingId, \DateTimeInterface $since): ?Candidate {
		if ($email === '') {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->orderBy('created_at', 'DESC')
			->setMaxResults(10);
		if ($openingId !== null) {
			$qb->andWhere($qb->expr()->eq('opening_id', $qb->createNamedParameter($openingId, IQueryBuilder::PARAM_INT)));
		}
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Candidates that arrived after a given moment — used by the digest,
	 * which must not load every candidate of every opening to find them.
	 *
	 * @param int[]|null $openingIds null = all openings
	 * @return Candidate[]
	 */
	public function findCreatedSince(\DateTimeInterface $since, ?array $openingIds): array {
		if ($openingIds !== null && $openingIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gt('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->andWhere($qb->expr()->isNotNull('opening_id'))
			->orderBy('created_at', 'ASC');
		if ($openingIds !== null) {
			$qb->andWhere($qb->expr()->in('opening_id', $qb->createNamedParameter($openingIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Talent pool: candidates who consented to be kept (spec §4.8).
	 *
	 * @return Candidate[]
	 */
	public function findPoolMembers(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pool_member', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('anonymized_at'))
			->orderBy('pool_consent_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function findByPoolToken(string $token): ?Candidate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pool_consent_token', $qb->createNamedParameter($token)));
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Pool members whose membership expired (consent older than cutoff).
	 *
	 * @return Candidate[]
	 */
	public function findExpiredPoolMembers(\DateTimeInterface $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pool_member', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('anonymized_at'))
			->andWhere($qb->expr()->lt('pool_consent_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		return $this->findEntities($qb);
	}

	/**
	 * Candidates whose retention period is over: rejected or withdrawn
	 * before the cutoff, not in the talent pool, not yet anonymized.
	 *
	 * @return Candidate[]
	 */
	public function findRetentionEligible(\DateTimeInterface $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$cutoffParam = $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_MUTABLE);
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('anonymized_at'))
			->andWhere($qb->expr()->eq('pool_member', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->eq('stage', $qb->createNamedParameter(Candidate::STAGE_REJECTED)),
					$qb->expr()->lt('rejected_at', $cutoffParam),
				),
				$qb->expr()->andX(
					$qb->expr()->eq('stage', $qb->createNamedParameter(Candidate::STAGE_WITHDRAWN)),
					$qb->expr()->lt('withdrawn_at', $cutoffParam),
				),
			));
		return $this->findEntities($qb);
	}

	/**
	 * Simple name/email search for the unified search provider.
	 *
	 * @return Candidate[]
	 */
	public function search(string $term, int $limit = 10): array {
		$qb = $this->db->getQueryBuilder();
		$like = '%' . $this->db->escapeLikeParameter(mb_strtolower($term)) . '%';
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->iLike('display_name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('email', $qb->createNamedParameter($like)),
			))
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Stage counts per opening for the navigation and board headers.
	 *
	 * @return array<int, array<string, int>> openingId => stage => count
	 */
	public function countByOpeningAndStage(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('opening_id', 'stage')
			->addSelect($qb->func()->count('id', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('opening_id'))
			->groupBy('opening_id', 'stage');
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['opening_id']][(string)$row['stage']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $counts;
	}
}
