<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Listener;

use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * When an account is deleted, remove its hiring-team memberships,
 * screening assignments and interview attendances. Cast votes and
 * timeline entries remain as anonymous history.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private TeamMemberMapper $teamMapper,
		private AssignmentMapper $assignmentMapper,
		private InterviewAttendeeMapper $attendeeMapper,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		$this->teamMapper->deleteForUser($uid);
		$this->assignmentMapper->deleteForUser($uid);
		$this->attendeeMapper->deleteForUser($uid);
	}
}
