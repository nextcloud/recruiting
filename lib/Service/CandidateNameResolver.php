<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Candidate names for published surfaces (activity stream, notifications).
 * Those only store the candidate id and look the name up when they are
 * rendered, so a deleted or anonymized candidate never reappears there by
 * name (spec §10.4).
 */
class CandidateNameResolver {
	/** @var array<int, Candidate|false> per-request cache, false = row is gone */
	private array $cache = [];

	public function __construct(
		private CandidateMapper $candidateMapper,
	) {
	}

	public function displayName(int $candidateId, IL10N $l): string {
		$candidate = $this->candidate($candidateId);
		if ($candidate === null) {
			return $l->t('Deleted candidate');
		}
		if ($candidate->getAnonymizedAt() !== null) {
			return $l->t('Anonymized candidate');
		}
		return $candidate->getDisplayName();
	}

	private function candidate(int $candidateId): ?Candidate {
		if ($candidateId <= 0) {
			return null;
		}
		if (!array_key_exists($candidateId, $this->cache)) {
			try {
				$this->cache[$candidateId] = $this->candidateMapper->find($candidateId);
			} catch (DoesNotExistException) {
				$this->cache[$candidateId] = false;
			}
		}
		return $this->cache[$candidateId] ?: null;
	}
}
