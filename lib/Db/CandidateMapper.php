<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCA\Recruiting\Service\ReportCache;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Every write of a candidate row goes through insert/update/delete here,
 * so this is where the cached report statistics of its opening are
 * dropped — whichever feature created, moved, anonymized or removed it.
 *
 * @extends QBMapper<Candidate>
 */
class CandidateMapper extends QBMapper {
	use ChunkedInQuery;

	public function __construct(
		IDBConnection $db,
		private ReportCache $reportCache,
	) {
		parent::__construct($db, 'recruiting_candidates', Candidate::class);
	}

	/**
	 * @param Candidate $entity
	 * @return Candidate
	 */
	#[\Override]
	public function insert(Entity $entity): Entity {
		$entity = parent::insert($entity);
		$this->invalidateReports($entity, false);
		return $entity;
	}

	/**
	 * @param Candidate $entity
	 * @return Candidate
	 */
	#[\Override]
	public function update(Entity $entity): Entity {
		$movedOpening = array_key_exists('openingId', $entity->getUpdatedFields());
		$entity = parent::update($entity);
		$this->invalidateReports($entity, $movedOpening);
		return $entity;
	}

	/**
	 * @param Candidate $entity
	 * @return Candidate
	 */
	#[\Override]
	public function delete(Entity $entity): Entity {
		$entity = parent::delete($entity);
		$this->invalidateReports($entity, false);
		return $entity;
	}

	/**
	 * The previous opening of a moved candidate is unknown here, so a move
	 * drops the statistics of all openings.
	 */
	private function invalidateReports(Candidate $candidate, bool $movedOpening): void {
		if ($movedOpening) {
			$this->reportCache->invalidate(null);
		} elseif ($candidate->getOpeningId() !== null) {
			$this->reportCache->invalidate($candidate->getOpeningId());
		}
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
	 * Every candidate of an opening, whatever the stage, anonymized ones
	 * included.
	 */
	public function countForOpening(int $openingId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('opening_id', $qb->createNamedParameter($openingId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * A cheap marker that changes whenever the board of an opening changes:
	 * candidates added or removed (count, highest id), moved (latest stage
	 * change) or touched in any way that leaves a timeline entry (votes,
	 * screeners, interviews, mail — highest event id). One aggregate query.
	 */
	public function revisionForOpening(int $openingId): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(DISTINCT ' . $qb->getColumnName('id', 'c') . ')'))
			->addSelect($qb->func()->max('c.id'))
			->addSelect($qb->func()->max('c.stage_changed_at'))
			->addSelect($qb->func()->max('e.id'))
			->from($this->getTableName(), 'c')
			->leftJoin('c', 'recruiting_events', 'e', $qb->expr()->eq('e.candidate_id', 'c.id'))
			->where($qb->expr()->eq('c.opening_id', $qb->createNamedParameter($openingId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetchNumeric();
		$result->closeCursor();
		return implode('-', array_map(static fn ($value) => (string)($value ?? '0'), $row === false ? [] : $row));
	}

	/**
	 * Candidates without an opening: the triage inbox.
	 *
	 * @return Candidate[]
	 */
	public function findTriage(int $limit = 0, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('opening_id'))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');
		if ($limit > 0) {
			$qb->setMaxResults($limit);
			$qb->setFirstResult(max(0, $offset));
		}
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
	 * Duplicate detection: same address (in any letter case), same opening,
	 * applied recently.
	 */
	public function findRecentByEmail(string $email, ?int $openingId, \DateTimeInterface $since): ?Candidate {
		if ($email === '') {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($this->emailMatches($qb, $email))
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
	public function findPoolMembers(int $limit = 0, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pool_member', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('anonymized_at'))
			->orderBy('pool_consent_at', 'DESC')
			->addOrderBy('id', 'DESC');
		if ($limit > 0) {
			$qb->setMaxResults($limit);
			$qb->setFirstResult(max(0, $offset));
		}
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

	public function findByPortalToken(string $token): ?Candidate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('portal_token', $qb->createNamedParameter($token)));
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Candidates an employee referred, newest first.
	 *
	 * @return Candidate[]
	 */
	public function findByReferrer(string $uid, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('referred_by', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->isNull('anonymized_at'))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Talent-pool members matching a search: name or email contains the
	 * term, or the candidate is one of the given ids (document-text hits).
	 *
	 * @param int[] $textMatchIds
	 * @return Candidate[]
	 */
	public function searchPoolMembers(string $term, array $textMatchIds, int $limit, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$like = '%' . $this->db->escapeLikeParameter(mb_strtolower($term)) . '%';
		$matches = $qb->expr()->orX(
			$qb->expr()->iLike('display_name', $qb->createNamedParameter($like)),
			$qb->expr()->iLike('email', $qb->createNamedParameter($like)),
		);
		if ($textMatchIds !== []) {
			$matches->add($qb->expr()->in('id', $qb->createNamedParameter(array_slice($textMatchIds, 0, 1000), IQueryBuilder::PARAM_INT_ARRAY)));
		}
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pool_member', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('anonymized_at'))
			->andWhere($matches)
			->orderBy('pool_consent_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult(max(0, $offset));
		return $this->findEntities($qb);
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
	 * Candidates left behind by a closed opening: still in an active stage
	 * when the opening was closed before the cutoff, not hired (hired is not
	 * an active stage), not in the talent pool, not yet anonymized. Rejected
	 * and withdrawn candidates follow their own clock
	 * (findRetentionEligible).
	 *
	 * @return Candidate[]
	 */
	public function findLeftInClosedOpenings(\DateTimeInterface $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.*')
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'recruiting_openings', 'o', $qb->expr()->eq('o.id', 'c.opening_id'))
			->where($qb->expr()->eq('o.status', $qb->createNamedParameter(Opening::STATUS_CLOSED)))
			->andWhere($qb->expr()->lt('o.closed_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->andWhere($qb->expr()->in('c.stage', $qb->createNamedParameter(Candidate::ACTIVE_STAGES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('c.pool_member', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('c.anonymized_at'));
		return $this->findEntities($qb);
	}

	/**
	 * Triage candidates (no opening) that arrived before the cutoff and
	 * never moved on. Talent-pool members are kept on their own clock.
	 *
	 * @return Candidate[]
	 */
	public function findTriageCreatedBefore(\DateTimeInterface $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('opening_id'))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->andWhere($qb->expr()->eq('pool_member', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Other applications by the same person (address compared in any letter
	 * case), newest first; anonymized ones are left out.
	 *
	 * @return Candidate[]
	 */
	public function findOtherApplications(string $email, int $excludeId, int $limit = 20): array {
		if ($email === '') {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($this->emailMatches($qb, $email))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('anonymized_at'))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Point duplicate hints at another candidate (after a merge removed
	 * the one they referred to).
	 */
	public function repointDuplicates(int $fromId, int $toId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('duplicate_of', $qb->createNamedParameter($toId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('duplicate_of', $qb->createNamedParameter($fromId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($toId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Name/email search for the unified search provider, restricted in SQL
	 * to what the scope allows and never returning anonymized rows. Newest
	 * first; $offset pages through the matches.
	 *
	 * @return Candidate[]
	 */
	public function search(string $term, CandidateScope $scope, int $limit, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$like = '%' . $this->db->escapeLikeParameter(mb_strtolower($term)) . '%';
		$qb->select('c.*')
			->from($this->getTableName(), 'c')
			->where($qb->expr()->orX(
				$qb->expr()->iLike('c.display_name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('c.email', $qb->createNamedParameter($like)),
			))
			->andWhere($qb->expr()->isNull('c.anonymized_at'));
		if (!$scope->everything) {
			$qb->andWhere($this->scopeCondition($qb, $scope));
		}
		$qb->orderBy('c.created_at', 'DESC')
			->addOrderBy('c.id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult(max(0, $offset));
		return $this->findEntities($qb);
	}

	/**
	 * The SQL form of PermissionService::canViewCandidate for a non-recruiter
	 * (alias "c" is the candidates table): every candidate of openings they
	 * manage or observe; in openings where they interview, only candidates
	 * assigned to them or whose interview they attend; plus, anywhere
	 * outside the triage inbox, the candidates of offers they are asked to
	 * approve.
	 */
	private function scopeCondition(IQueryBuilder $qb, CandidateScope $scope): ICompositeExpression {
		$uid = $qb->createNamedParameter($scope->uid);
		$conditions = [];

		if ($scope->fullOpeningIds !== []) {
			$conditions[] = $qb->expr()->in('c.opening_id', $qb->createNamedParameter($scope->fullOpeningIds, IQueryBuilder::PARAM_INT_ARRAY));
		}

		if ($scope->assignedOpeningIds !== []) {
			$assigned = $this->db->getQueryBuilder();
			$assigned->select('sa.candidate_id')
				->from('recruiting_assignments', 'sa')
				->where($assigned->expr()->eq('sa.uid', $uid));
			$attending = $this->db->getQueryBuilder();
			$attending->select('si.candidate_id')
				->from('recruiting_interviews', 'si')
				->innerJoin('si', 'recruiting_intv_attendees', 'sat', $attending->expr()->eq('sat.interview_id', 'si.id'))
				->where($attending->expr()->eq('sat.uid', $uid));
			$conditions[] = $qb->expr()->andX(
				$qb->expr()->in('c.opening_id', $qb->createNamedParameter($scope->assignedOpeningIds, IQueryBuilder::PARAM_INT_ARRAY)),
				$qb->expr()->orX(
					$qb->expr()->in('c.id', $qb->createFunction($assigned->getSQL())),
					$qb->expr()->in('c.id', $qb->createFunction($attending->getSQL())),
				),
			);
		}

		$approving = $this->db->getQueryBuilder();
		$approving->select('so.candidate_id')
			->from('recruiting_offers', 'so')
			->where($approving->expr()->eq('so.approver_uid', $uid));
		$conditions[] = $qb->expr()->andX(
			$qb->expr()->isNotNull('c.opening_id'),
			$qb->expr()->in('c.id', $qb->createFunction($approving->getSQL())),
		);

		return $qb->expr()->orX(...$conditions);
	}

	/**
	 * Addresses are compared in lower case on every database (PostgreSQL
	 * and SQLite compare case-sensitively).
	 */
	private function emailMatches(IQueryBuilder $qb, string $email): string {
		return $qb->expr()->eq($qb->func()->lower('email'), $qb->createNamedParameter(mb_strtolower(trim($email))));
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
