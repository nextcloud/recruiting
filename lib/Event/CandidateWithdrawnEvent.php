<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Event;

use OCA\Recruiting\Db\Candidate;
use OCP\EventDispatcher\Event;

/**
 * A candidate moved to "withdrawn" — on their own page or by a manual
 * stage change.
 */
class CandidateWithdrawnEvent extends Event {
	public function __construct(
		private Candidate $candidate,
		private ?string $actorUid,
	) {
		parent::__construct();
	}

	public function getCandidate(): Candidate {
		return $this->candidate;
	}

	/**
	 * The team member who moved the candidate, or null when the candidate
	 * withdrew themselves.
	 */
	public function getActorUid(): ?string {
		return $this->actorUid;
	}
}
