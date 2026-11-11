<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Listener;

use OCA\Recruiting\Event\CandidateWithdrawnEvent;
use OCA\Recruiting\Service\InterviewService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * A candidate who withdrew will not come to their interviews: cancel the
 * open ones and tell the interviewers.
 *
 * @template-implements IEventListener<CandidateWithdrawnEvent>
 */
class CandidateWithdrawnListener implements IEventListener {
	public function __construct(
		private InterviewService $interviews,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof CandidateWithdrawnEvent) {
			return;
		}
		$this->interviews->cancelOpenFor($event->getCandidate(), $event->getActorUid());
	}
}
