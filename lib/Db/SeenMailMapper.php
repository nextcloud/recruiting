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
 * Ledger of incoming mails, keyed by Message-ID (or a hash of the raw
 * content for mails without one). IMAP "seen" flags are the primary
 * bookkeeping; this table makes ingestion idempotent and resumable.
 *
 * A row is written `pending` before a mail is processed and counts its
 * `attempts` from that moment on, so even an attempt that dies with a
 * fatal error is counted. The candidate created for the mail is stored on
 * the row right after its insert, so a retry continues on that candidate.
 * Only a fully processed mail is marked done (`pending` = 0).
 */
class SeenMailMapper {
	private const TABLE = 'recruiting_mail_seen';

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Count one more attempt at a mail before it is processed.
	 *
	 * @return array{done: bool, attempts: int, candidateId: ?int} `done` when
	 *                                                             the mail was ingested before; `attempts` includes this one
	 */
	public function beginAttempt(string $key): array {
		$row = $this->row($key);
		if ($row === null) {
			$inserted = $this->db->insertIgnoreConflict(self::TABLE, [
				'message_id' => $key,
				'created_at' => $this->now(),
				'pending' => 1,
				'attempts' => 1,
			]);
			if ($inserted > 0) {
				return ['done' => false, 'attempts' => 1, 'candidateId' => null];
			}
			// A parallel run inserted it first
			$row = $this->row($key) ?? ['pending' => 1, 'attempts' => 0, 'candidate_id' => null];
		}
		$candidateId = $row['candidate_id'] !== null ? (int)$row['candidate_id'] : null;
		if ((int)$row['pending'] === 0) {
			return ['done' => true, 'attempts' => (int)$row['attempts'], 'candidateId' => $candidateId];
		}

		$attempts = (int)$row['attempts'] + 1;
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('attempts', $qb->createNamedParameter($attempts, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($key)));
		$qb->executeStatement();
		return ['done' => false, 'attempts' => $attempts, 'candidateId' => $candidateId];
	}

	/**
	 * Remember the candidate a mail created or continued.
	 */
	public function attachCandidate(string $key, int $candidateId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('candidate_id', $qb->createNamedParameter($candidateId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($key)));
		$qb->executeStatement();
	}

	/**
	 * Mark a mail as fully processed; its attempt count starts over.
	 */
	public function remember(string $key): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('pending', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('last_error', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($key)));
		if ($qb->executeStatement() > 0) {
			return;
		}
		$this->db->insertIgnoreConflict(self::TABLE, [
			'message_id' => $key,
			'created_at' => $this->now(),
		]);
	}

	/**
	 * Note why the current attempt failed.
	 */
	public function recordError(string $key, string $error): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('last_error', $qb->createNamedParameter(mb_substr($error, 0, 250)))
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($key)));
		$qb->executeStatement();
	}

	/**
	 * Note that a mail went to the quarantine folder. The attempt count
	 * starts over, so a mail moved back into the inbox is tried again.
	 */
	public function markQuarantined(string $key, ?string $error = null): void {
		$this->db->insertIgnoreConflict(self::TABLE, [
			'message_id' => $key,
			'created_at' => $this->now(),
			'pending' => 1,
		]);
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('quarantined_at', $qb->createNamedParameter($this->now()))
			->set('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($key)));
		if ($error !== null) {
			$qb->set('last_error', $qb->createNamedParameter(mb_substr($error, 0, 250)));
		}
		$qb->executeStatement();
	}

	/**
	 * @return array{pending: int|string, attempts: int|string, candidate_id: int|string|null}|null
	 */
	private function row(string $key): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('pending', 'attempts', 'candidate_id')
			->from(self::TABLE)
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($key)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function now(): string {
		return $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
	}
}
