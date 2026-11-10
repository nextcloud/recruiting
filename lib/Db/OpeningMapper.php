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
 * @extends QBMapper<Opening>
 */
class OpeningMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recruiting_openings', Opening::class);
	}

	public function find(int $id): Opening {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Opening[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Openings whose title contains the term (any letter case), newest
	 * first, limited to $openingIds unless null.
	 *
	 * @param int[]|null $openingIds
	 * @return Opening[]
	 */
	public function searchByTitle(string $term, ?array $openingIds, int $limit): array {
		if ($openingIds === [] || $limit <= 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$like = '%' . $this->db->escapeLikeParameter(mb_strtolower($term)) . '%';
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->iLike('title', $qb->createNamedParameter($like)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		if ($openingIds !== null) {
			$qb->andWhere($qb->expr()->in('id', $qb->createNamedParameter($openingIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * The title of an opening, or null when there is none / it vanished —
	 * every surface that labels a candidate needs exactly this.
	 */
	public function findTitle(?int $id): ?string {
		if ($id === null) {
			return null;
		}
		try {
			return $this->find($id)->getTitle();
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function findByMailSlug(string $slug): ?Opening {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mail_slug', $qb->createNamedParameter($slug)));
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	public function mailSlugExists(string $slug): bool {
		return $this->findByMailSlug($slug) !== null;
	}
}
