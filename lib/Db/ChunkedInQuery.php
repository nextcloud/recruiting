<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

/**
 * Splits id lists for IN() clauses. Databases cap the number of bound
 * parameters (Oracle at 1000, SQLite at 999 by default), so every batch
 * lookup that takes "all candidates of an opening" must chunk — an opening
 * with a few thousand applications would otherwise break the board.
 */
trait ChunkedInQuery {
	/**
	 * @param int[] $ids
	 * @return array<int, int[]>
	 */
	protected function idChunks(array $ids): array {
		return array_chunk(array_values(array_unique($ids)), 500);
	}
}
