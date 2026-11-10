<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Aggregate and narrow queries for the reports: applications of a set of
 * openings received in a date window. Counts are grouped in SQL; timing
 * figures read only the columns they need — never whole candidate rows.
 */
class ReportMapper {
	use ChunkedInQuery;

	private const GROUPABLE = ['stage', 'source', 'rejection_reason'];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Applications per opening and value of one column.
	 *
	 * @param int[] $openingIds
	 * @return array<int, array<string, int>> openingId => value => count
	 */
	public function countBy(string $column, array $openingIds, \DateTimeInterface $from, \DateTimeInterface $to): array {
		if (!in_array($column, self::GROUPABLE, true)) {
			throw new \InvalidArgumentException('Not a groupable column: ' . $column);
		}
		$counts = [];
		foreach ($this->idChunks($openingIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('opening_id', $column)
				->addSelect($qb->func()->count('id', 'cnt'))
				->from('recruiting_candidates')
				->where($qb->expr()->in('opening_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->isNotNull($column));
			$this->window($qb, 'created_at', $from, $to);
			$qb->groupBy('opening_id', $column);
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$value = (string)$row[$column];
				if ($value !== '') {
					$counts[(int)$row['opening_id']][$value] = (int)$row['cnt'];
				}
			}
			$result->closeCursor();
		}
		return $counts;
	}

	/**
	 * The timing columns of every application in the window.
	 *
	 * @param int[] $openingIds
	 * @return array<int, list<array{id: int, stage: string, createdAt: int, hiredAt: ?int}>> openingId => rows
	 */
	public function applications(array $openingIds, \DateTimeInterface $from, \DateTimeInterface $to): array {
		$rows = [];
		foreach ($this->idChunks($openingIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'opening_id', 'stage', 'created_at', 'hired_at')
				->from('recruiting_candidates')
				->where($qb->expr()->in('opening_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$this->window($qb, 'created_at', $from, $to);
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$rows[(int)$row['opening_id']][] = [
					'id' => (int)$row['id'],
					'stage' => (string)$row['stage'],
					'createdAt' => self::timestamp($row['created_at']),
					'hiredAt' => $row['hired_at'] !== null ? self::timestamp($row['hired_at']) : null,
				];
			}
			$result->closeCursor();
		}
		return $rows;
	}

	/**
	 * Stage changes of the applications in the window, oldest first.
	 * Events carried over from a merged duplicate belong to another
	 * application's history and are left out.
	 *
	 * @param int[] $openingIds
	 * @return array<int, list<array{at: int, from: string, to: string}>> candidateId => events
	 */
	public function stageChanges(array $openingIds, \DateTimeInterface $from, \DateTimeInterface $to): array {
		$events = [];
		foreach ($this->idChunks($openingIds) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('e.candidate_id', 'e.created_at', 'e.data')
				->from('recruiting_events', 'e')
				->innerJoin('e', 'recruiting_candidates', 'c', $qb->expr()->eq('c.id', 'e.candidate_id'))
				->where($qb->expr()->in('c.opening_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('e.type', $qb->createNamedParameter(TimelineEvent::TYPE_STAGE_CHANGED)));
			$this->window($qb, 'c.created_at', $from, $to);
			$qb->orderBy('e.candidate_id', 'ASC')
				->addOrderBy('e.created_at', 'ASC')
				->addOrderBy('e.id', 'ASC');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$data = json_decode((string)($row['data'] ?? ''), true);
				if (!is_array($data) || isset($data[TimelineEvent::MERGED_FROM])) {
					continue;
				}
				$events[(int)$row['candidate_id']][] = [
					'at' => self::timestamp($row['created_at']),
					'from' => (string)($data['from'] ?? ''),
					'to' => (string)($data['to'] ?? ''),
				];
			}
			$result->closeCursor();
		}
		return $events;
	}

	/**
	 * [$from, $to) on a datetime column.
	 */
	private function window(IQueryBuilder $qb, string $column, \DateTimeInterface $from, \DateTimeInterface $to): void {
		$qb->andWhere($qb->expr()->gte($column, $qb->createNamedParameter(\DateTime::createFromInterface($from), IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->andWhere($qb->expr()->lt($column, $qb->createNamedParameter(\DateTime::createFromInterface($to), IQueryBuilder::PARAM_DATETIME_MUTABLE)));
	}

	/**
	 * Datetime columns hold UTC wall-clock values.
	 */
	private static function timestamp(mixed $value): int {
		return (new \DateTimeImmutable((string)$value, new \DateTimeZone('UTC')))->getTimestamp();
	}
}
