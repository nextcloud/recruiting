<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Activity;

use OCA\Recruiting\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

class Provider implements IProvider {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
	) {
	}

	#[\Override]
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException('Not a Recruiting event');
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		$params = $event->getSubjectParameters();
		$candidate = (string)($params['candidate'] ?? '');
		$actor = $this->displayName($event->getAuthor());
		$title = (string)($params['title'] ?? '');

		$subject = match ($event->getSubject()) {
			'application_created' => $l->t('New application from %s', [$candidate]),
			'stage_changed' => $l->t('%1$s moved %2$s to "%3$s"', [
				$actor !== '' ? $actor : $l->t('Someone'),
				$candidate,
				$this->stageName($l, (string)($params['toStage'] ?? '')),
			]),
			'vote_cast' => $l->t('%1$s cast a screening vote on %2$s', [$actor, $candidate]),
			'mail_sent' => $l->t('%1$s sent an email to %2$s', [
				$actor !== '' ? $actor : $l->t('Recruiting'),
				$candidate,
			]),
			'interview_scheduled' => $l->t('%1$s proposed the interview "%2$s" to %3$s', [$actor, $title, $candidate]),
			'interview_confirmed' => $l->t('%1$s confirmed the interview "%2$s"', [$candidate, $title]),
			'interview_cancelled' => $l->t('The interview "%1$s" with %2$s was cancelled', [$title, $candidate]),
			'candidate_rejected' => $l->t('%1$s rejected %2$s', [$actor, $candidate]),
			'offer_created' => $l->t('%1$s drafted an offer for %2$s', [$actor, $candidate]),
			'offer_approved' => $l->t('%1$s approved the offer for %2$s', [$actor, $candidate]),
			'offer_sent' => $l->t('%1$s sent an offer to %2$s', [$actor, $candidate]),
			'offer_accepted' => $l->t('🎉 %s accepted the offer', [$candidate]),
			'offer_declined' => $l->t('%s declined the offer', [$candidate]),
			'offer_expired' => $l->t('The offer for %s expired without a response', [$candidate]),
			default => throw new UnknownActivityException('Unknown subject'),
		};

		$event->setParsedSubject($subject);
		$event->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));
		if ($event->getObjectId() > 0) {
			$event->setLink($this->urlGenerator->linkToRouteAbsolute('recruiting.page.index') . '#/candidate/' . $event->getObjectId());
		}
		return $event;
	}

	private function stageName(\OCP\IL10N $l, string $stage): string {
		return match ($stage) {
			'new' => $l->t('New'),
			'screening' => $l->t('Screening'),
			'interview' => $l->t('Interview'),
			'offer' => $l->t('Offer'),
			'hired' => $l->t('Hired'),
			'rejected' => $l->t('Rejected'),
			'withdrawn' => $l->t('Withdrawn'),
			default => $stage,
		};
	}

	private function displayName(string $uid): string {
		if ($uid === '') {
			return '';
		}
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}
}
