<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Removes this app's entries from the Activity app's tables.
 *
 * OCP offers no API to delete published activities, so this writes to the
 * activity app's tables directly (schema of nextcloud/activity:
 * `activity` and the mail queue `activity_mq`). Both may be missing when the
 * activity app was never installed. Everything here is best-effort: a
 * failure is logged and never aborts the deletion that triggered it.
 */
class ActivityPurgeService {
	public const OBJECT_TYPE = 'candidate';
	private const BATCH_SIZE = 500;

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Delete every activity entry about one candidate (GDPR delete and
	 * anonymization).
	 */
	public function purgeForCandidate(int $candidateId): void {
		try {
			if ($this->db->tableExists('activity')) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('activity')
					->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)))
					->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter(self::OBJECT_TYPE)))
					->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			}
			if ($this->db->tableExists('activity_mq')) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('activity_mq')
					->where($qb->expr()->eq('amq_appid', $qb->createNamedParameter(Application::APP_ID)))
					->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter(self::OBJECT_TYPE)))
					->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not remove the activity entries of candidate ' . $candidateId . ': ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Remove the candidate name that older versions stored in the
	 * subject parameters of every entry.
	 *
	 * @return int number of rows rewritten
	 */
	public function stripStoredNames(): int {
		$rewritten = 0;
		if ($this->db->tableExists('activity')) {
			$rewritten += $this->stripTable('activity', 'activity_id', 'app', 'subjectparams');
		}
		if ($this->db->tableExists('activity_mq')) {
			$rewritten += $this->stripTable('activity_mq', 'mail_id', 'amq_appid', 'amq_subjectparams');
		}
		return $rewritten;
	}

	private function stripTable(string $table, string $idColumn, string $appColumn, string $paramsColumn): int {
		$rewritten = 0;
		$lastId = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select($idColumn, $paramsColumn)
				->from($table)
				->where($qb->expr()->eq($appColumn, $qb->createNamedParameter(Application::APP_ID)))
				->andWhere($qb->expr()->gt($idColumn, $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->like($paramsColumn, $qb->createNamedParameter('%"candidate":%')))
				->orderBy($idColumn, 'ASC')
				->setMaxResults(self::BATCH_SIZE);
			$result = $qb->executeQuery();
			$rows = $result->fetchAllAssociative();
			$result->closeCursor();

			foreach ($rows as $row) {
				$lastId = (int)$row[$idColumn];
				$stripped = self::withoutName((string)$row[$paramsColumn]);
				if ($stripped === null) {
					continue;
				}
				$update = $this->db->getQueryBuilder();
				$update->update($table)
					->set($paramsColumn, $update->createNamedParameter($stripped))
					->where($update->expr()->eq($idColumn, $update->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)));
				$update->executeStatement();
				$rewritten++;
			}
		} while (count($rows) === self::BATCH_SIZE);
		return $rewritten;
	}

	/**
	 * @return ?string the parameters without the `candidate` key, or null when
	 *                 there is nothing to change
	 */
	public static function withoutName(string $json): ?string {
		$params = json_decode($json, true);
		if (!is_array($params) || !array_key_exists('candidate', $params)) {
			return null;
		}
		unset($params['candidate']);
		return json_encode($params) ?: '[]';
	}
}
