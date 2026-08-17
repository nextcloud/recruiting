<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Notification;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Service\NotificationService;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Recruiting');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();

		$candidateName = (string)($parameters['candidateName'] ?? '');
		$candidateId = (string)($parameters['candidateId'] ?? '0');
		$actorUid = (string)($parameters['actor'] ?? '');
		$interviewTitle = (string)($parameters['interviewTitle'] ?? '');
		$openingTitle = (string)($parameters['openingTitle'] ?? '');

		$richParameters = [
			'candidate' => [
				'type' => 'highlight',
				'id' => $candidateId,
				'name' => $candidateName !== '' ? $candidateName : $l->t('a candidate'),
			],
		];
		if ($actorUid !== '') {
			$richParameters['actor'] = [
				'type' => 'user',
				'id' => $actorUid,
				'name' => $this->userManager->getDisplayName($actorUid) ?? $actorUid,
			];
		}

		switch ($notification->getSubject()) {
			case NotificationService::SUBJECT_SCREENER_ASSIGNED:
				$subject = $actorUid !== ''
					? $l->t('{actor} asked you to review {candidate}')
					: $l->t('You were asked to review {candidate}');
				break;
			case NotificationService::SUBJECT_INTERVIEW_ASSIGNED:
				$subject = $actorUid !== ''
					? $l->t('{actor} added you as interviewer for {candidate}')
					: $l->t('You were added as interviewer for {candidate}');
				break;
			case NotificationService::SUBJECT_INTERVIEW_CONFIRMED:
				$subject = $l->t('{candidate} confirmed the interview "{interview}"');
				$richParameters['interview'] = [
					'type' => 'highlight',
					'id' => 'interview',
					'name' => $interviewTitle,
				];
				break;
			case NotificationService::SUBJECT_INTERVIEW_CANCELLED:
				$subject = $l->t('The interview "{interview}" with {candidate} was cancelled');
				$richParameters['interview'] = [
					'type' => 'highlight',
					'id' => 'interview',
					'name' => $interviewTitle,
				];
				break;
			case NotificationService::SUBJECT_MENTION:
				$subject = $l->t('{actor} mentioned you on {candidate}');
				break;
			case NotificationService::SUBJECT_OFFER_APPROVAL_REQUESTED:
				$subject = $actorUid !== ''
					? $l->t('{actor} asks you to approve the offer for {candidate}')
					: $l->t('An offer for {candidate} awaits your approval');
				break;
			case NotificationService::SUBJECT_OFFER_APPROVED:
				$subject = $l->t('{actor} approved the offer for {candidate}');
				break;
			case NotificationService::SUBJECT_OFFER_APPROVAL_DECLINED:
				$subject = $l->t('{actor} sent the offer for {candidate} back to draft');
				break;
			case NotificationService::SUBJECT_OFFER_EXPIRING:
				$subject = $l->t('The offer for {candidate} expires soon ({validUntil})');
				$richParameters['validUntil'] = [
					'type' => 'highlight',
					'id' => 'validUntil',
					'name' => (string)($parameters['validUntil'] ?? ''),
				];
				break;
			case NotificationService::SUBJECT_OFFER_EXPIRED:
				$subject = $l->t('The offer for {candidate} has expired without a response');
				break;
			case NotificationService::SUBJECT_CANDIDATE_REPLIED:
				$subject = $l->t('{candidate} replied by email');
				break;
			case NotificationService::SUBJECT_NEW_APPLICATION:
				$subject = $openingTitle !== ''
					? $l->t('New application from {candidate} for {opening}')
					: $l->t('New application from {candidate}');
				$richParameters['opening'] = [
					'type' => 'highlight',
					'id' => 'opening',
					'name' => $openingTitle,
				];
				break;
			default:
				throw new UnknownNotificationException();
		}

		$notification->setRichSubject($subject, $richParameters);
		$notification->setLink(
			$this->urlGenerator->linkToRouteAbsolute('recruiting.page.index')
			. '#/candidate/' . $candidateId,
		);
		$notification->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
		));
		return $notification;
	}
}
