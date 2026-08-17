<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The one place where a candidate changes stage — used by manual moves,
 * rejection and the automatic move on interview confirmation.
 */
class StageService {
	public function __construct(
		private CandidateMapper $candidateMapper,
		private TimelineService $timeline,
		private ActivityPublisher $activity,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Move a candidate to a new stage.
	 *
	 * Leaving a terminal stage (hired / rejected / withdrawn) is a deliberate
	 * decision, never a side effect: a late offer response or a candidate
	 * confirming an interview slot must not resurrect somebody who was
	 * rejected or withdrew in the meantime. Callers that legitimately reopen a
	 * candidate (the manual stage change in the UI) pass $allowFromTerminal.
	 *
	 * @return bool whether the stage actually changed
	 */
	public function apply(Candidate $candidate, string $stage, ?string $actorUid, array $timelineData = [], bool $allowFromTerminal = false): bool {
		$from = $candidate->getStage();
		if ($from === $stage) {
			return false;
		}
		if (!$allowFromTerminal && $candidate->isTerminal()) {
			return false;
		}
		$now = $this->timeFactory->getDateTime();
		$candidate->setStage($stage);
		$candidate->setStageChangedAt($now);
		$candidate->setHiredAt($stage === Candidate::STAGE_HIRED ? $now : null);
		$candidate->setWithdrawnAt($stage === Candidate::STAGE_WITHDRAWN ? $now : null);
		if ($stage === Candidate::STAGE_REJECTED) {
			$candidate->setRejectedAt($now);
		} else {
			$candidate->setRejectedAt(null);
			$candidate->setRejectionReason(null);
		}
		$this->candidateMapper->update($candidate);

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_STAGE_CHANGED, $actorUid, array_merge([
			'from' => $from,
			'to' => $stage,
		], $timelineData));
		$this->activity->publish($candidate, 'stage_changed', $actorUid, ['fromStage' => $from, 'toStage' => $stage]);
		return true;
	}
}
