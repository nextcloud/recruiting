<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCP\AppFramework\Utility\ITimeFactory;

class TimelineService {
	public function __construct(
		private TimelineEventMapper $mapper,
		private ITimeFactory $timeFactory,
	) {
	}

	public function record(int $candidateId, string $type, ?string $actorUid, array $data = []): TimelineEvent {
		$event = new TimelineEvent();
		$event->setCandidateId($candidateId);
		$event->setType($type);
		$event->setActorUid($actorUid);
		$event->setData($data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));
		$event->setCreatedAt($this->timeFactory->getDateTime());
		return $this->mapper->insert($event);
	}

	/**
	 * @return TimelineEvent[]
	 */
	public function eventsFor(int $candidateId): array {
		return $this->mapper->findForCandidate($candidateId);
	}
}
