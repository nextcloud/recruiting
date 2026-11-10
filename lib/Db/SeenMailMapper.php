<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Ledger of ingested Message-IDs. IMAP "seen" flags are the primary
 * bookkeeping, but a crash between processing a mail and marking it seen
 * would re-ingest it on the next run — this table makes ingestion
 * idempotent. Recorded *after* successful processing on purpose: a crash
 * mid-processing retries the mail (at-least-once) instead of losing the
 * application silently.
 *
 * Failed attempts are tracked in the same table: such rows are `pending`
 * (not ingested yet) and count `attempts` until the mail is quarantined.
 * Mails without a Message-ID are keyed by a hash of their raw content.
 */
class SeenMailMapper {
	private const TABLE = 'recruiting_mail_seen';

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
	}

	public function hasSeen(string $messageId): bool {
		if ($messageId === '') {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
			->andWhere($qb->expr()->eq('pending', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	public function remember(string $messageId): void {
		if ($messageId === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('pending', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)));
		if ($qb->executeStatement() > 0) {
			return;
		}
		$this->db->insertIgnoreConflict(self::TABLE, [
			'message_id' => $messageId,
			'created_at' => $this->now(),
		]);
	}

	/**
	 * Count one more failed attempt for a mail.
	 *
	 * @return int the number of failed attempts including this one
	 */
	public function recordFailure(string $key, string $error): int {
		$error = mb_substr($error, 0, 250);
		$attempts = $this->attempts($key);
		if ($attempts === null) {
			$this->db->insertIgnoreConflict(self::TABLE, [
				'message_id' => $key,
				'created_at' => $this->now(),
				'pending' => 1,
				'attempts' => 1,
				'last_error' => $error,
			]);
			return 1;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('attempts', $qb->createNamedParameter($attempts + 1, IQueryBuilder::PARAM_INT))
			->set('last_error', $qb->createNamedParameter($error))
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($key)));
		$qb->executeStatement();
		return $attempts + 1;
	}

	public function markQuarantined(string $key): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('quarantined_at', $qb->createNamedParameter($this->now()))
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($key)));
		$qb->executeStatement();
	}

	private function attempts(string $key): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('attempts')
			->from(self::TABLE)
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($key)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : (int)$row['attempts'];
	}

	private function now(): string {
		return $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
	}
}
