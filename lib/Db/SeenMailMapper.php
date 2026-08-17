<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Ledger of ingested Message-IDs. IMAP "seen" flags are the primary
 * bookkeeping, but a crash between processing a mail and marking it seen
 * would re-ingest it on the next run — this table makes ingestion
 * idempotent. Recorded *after* successful processing on purpose: a crash
 * mid-processing retries the mail (at-least-once) instead of losing the
 * application silently.
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
		$this->db->insertIgnoreConflict(self::TABLE, [
			'message_id' => $messageId,
			'created_at' => $this->timeFactory->getDateTime()->format('Y-m-d H:i:s'),
		]);
	}
}
