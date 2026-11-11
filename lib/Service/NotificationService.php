<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\Opening;
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
	public const SUBJECT_AUTO_ACK_CAP_REACHED = 'auto_ack_cap_reached';
	public const SUBJECT_CANDIDATE_WITHDREW = 'candidate_withdrew';
	public const SUBJECT_DELETION_REQUESTED = 'deletion_requested';
	public const SUBJECT_CANDIDATE_UPLOADED = 'candidate_uploaded';
	public const SUBJECT_INTERVIEW_SLOTS_DECLINED = 'interview_slots_declined';
	public const SUBJECT_INTERVIEW_RESCHEDULE_REQUESTED = 'interview_reschedule_requested';
	public const SUBJECT_INTERVIEW_RESCHEDULED = 'interview_rescheduled';
	/** To the opening's managers once every interviewer of a round gave feedback */
	public const SUBJECT_INTERVIEW_FEEDBACK_COMPLETE = 'interview_feedback_complete';
	/** To the employee who referred the candidate; carries only the coarse status */
	public const SUBJECT_REFERRAL_STATUS = 'referral_status';

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
		// The name is resolved by Notification\Notifier when shown
		$parameters['candidateId'] = (string)$candidate->getId();
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
	 * Tell recruiters that an opening's automatic acknowledgements stopped
	 * for today because the daily cap was reached.
	 *
	 * @param string[] $recipients user ids
	 */
	public function notifyAutoAckCapReached(array $recipients, Opening $opening, int $cap): void {
		$parameters = [
			'openingId' => (string)$opening->getId(),
			'openingTitle' => $opening->getTitle(),
			'cap' => (string)$cap,
		];
		foreach (array_unique($recipients) as $uid) {
			if ($uid === '') {
				continue;
			}
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime($this->timeFactory->getDateTime())
				->setObject('opening', (string)$opening->getId())
				->setSubject(self::SUBJECT_AUTO_ACK_CAP_REACHED, $parameters);
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
