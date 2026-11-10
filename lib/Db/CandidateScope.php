<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

/**
 * Which candidates a user may see, in a form a query can apply — the SQL
 * twin of PermissionService::canViewCandidate:
 *
 *  - everything: recruiters, including the triage inbox
 *  - fullOpeningIds: openings where the user is manager or observer
 *  - assignedOpeningIds: openings where the user is interviewer — only
 *    candidates assigned to them or whose interview they attend
 *  - offer approvers additionally see the candidates of offers they are
 *    asked to approve, in any opening
 */
final class CandidateScope {
	/**
	 * @param int[] $fullOpeningIds
	 * @param int[] $assignedOpeningIds
	 */
	public function __construct(
		public readonly string $uid,
		public readonly bool $everything,
		public readonly array $fullOpeningIds = [],
		public readonly array $assignedOpeningIds = [],
	) {
	}

	/**
	 * Every opening the user holds a role in (what canViewOpening allows).
	 *
	 * @return int[]
	 */
	public function visibleOpeningIds(): array {
		return array_values(array_unique(array_merge($this->fullOpeningIds, $this->assignedOpeningIds)));
	}
}
