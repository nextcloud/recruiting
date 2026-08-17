<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Db\Candidate;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;

/**
 * Sends bell notifications. Rendering happens in Notification\Notifier.
 */
class NotificationService {
	public const SUBJECT_SCREENER_ASSIGNED = 'screener_assigned';
	public const SUBJECT_INTERVIEW_ASSIGNED = 'interview_assigned';
	public const SUBJECT_INTERVIEW_CONFIRMED = 'interview_confirmed';
	public const SUBJECT_INTERVIEW_CANCELLED = 'interview_cancelled';
	public const SUBJECT_MENTION = 'mention';
	public const SUBJECT_NEW_APPLICATION = 'new_application';
	public const SUBJECT_CANDIDATE_REPLIED = 'candidate_replied';
	public const SUBJECT_OFFER_APPROVAL_REQUESTED = 'offer_approval_requested';
	public const SUBJECT_OFFER_APPROVED = 'offer_approved';
	public const SUBJECT_OFFER_APPROVAL_DECLINED = 'offer_approval_declined';
	public const SUBJECT_OFFER_EXPIRING = 'offer_expiring';
	public const SUBJECT_OFFER_EXPIRED = 'offer_expired';

	public function __construct(
		private INotificationManager $notificationManager,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param string[] $recipients user ids; the actor is filtered out
	 * @param array<string, string> $parameters
	 */
	public function notify(array $recipients, string $subject, Candidate $candidate, ?string $actorUid, array $parameters = []): void {
		$parameters['candidateId'] = (string)$candidate->getId();
		$parameters['candidateName'] = $candidate->getDisplayName();
		$parameters['openingId'] = (string)($candidate->getOpeningId() ?? 0);
		if ($actorUid !== null) {
			$parameters['actor'] = $actorUid;
		}

		foreach (array_unique($recipients) as $uid) {
			if ($uid === '' || $uid === $actorUid) {
				continue;
			}
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime($this->timeFactory->getDateTime())
				->setObject('candidate', (string)$candidate->getId())
				->setSubject($subject, $parameters);
			$this->notificationManager->notify($notification);
		}
	}

	/**
	 * Remove all notifications referring to a candidate (deletion / GDPR).
	 */
	public function purgeForCandidate(int $candidateId): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setObject('candidate', (string)$candidateId);
		$this->notificationManager->markProcessed($notification);
	}
}
