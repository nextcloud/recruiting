<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Dashboard;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Service\TodayService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * The "Today" overview on the Nextcloud Dashboard, compact: offers to
 * approve, interviews, votes owed, stuck candidates and the triage inbox.
 * Only registered for users with a part in recruiting.
 */
class TodayWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IConditionalWidget, IReloadableWidget {
	public function __construct(
		private TodayService $today,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IDateTimeFormatter $dateTimeFormatter,
		private IL10N $l10n,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'recruiting-today';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Recruiting today');
	}

	#[\Override]
	public function getOrder(): int {
		return 30;
	}

	#[\Override]
	public function getIconClass(): string {
		return 'icon-recruiting';
	}

	#[\Override]
	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	#[\Override]
	public function getUrl(): ?string {
		return $this->appUrl();
	}

	#[\Override]
	public function load(): void {
	}

	#[\Override]
	public function isEnabled(): bool {
		return $this->today->hasRecruitingRole($this->userSession->getUser()?->getUID() ?? '');
	}

	#[\Override]
	public function getReloadInterval(): int {
		return 300;
	}

	#[\Override]
	public function getWidgetButtons(string $userId): array {
		return [new WidgetButton(WidgetButton::TYPE_MORE, $this->appUrl(), $this->l10n->t('Open Recruiting'))];
	}

	#[\Override]
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$today = $this->today->forUser($userId);
		$icon = $this->getIconUrl();
		$items = [];

		foreach ($today['approvals'] as $approval) {
			$items[] = new WidgetItem(
				$this->l10n->t('Approve the offer for %s', [$approval['candidate']['displayName']]),
				(string)$approval['jobTitle'],
				$this->candidateUrl($approval['candidate']['id']),
				$icon,
			);
		}
		foreach ($today['interviews'] as $interview) {
			$items[] = new WidgetItem(
				$this->l10n->t('%1$s with %2$s', [$interview['title'], $interview['candidate']['displayName']]),
				$this->dateTimeFormatter->formatDateTime(new \DateTime((string)$interview['startAt']), 'short', 'short'),
				$this->candidateUrl($interview['candidate']['id']),
				$icon,
			);
		}
		foreach ($today['votesOwed'] as $candidate) {
			$items[] = new WidgetItem(
				$this->l10n->t('Review %s', [$candidate['displayName']]),
				(string)($candidate['openingTitle'] ?? ''),
				$this->candidateUrl($candidate['id']),
				$icon,
			);
		}
		foreach ($today['stuck'] as $candidate) {
			$items[] = new WidgetItem(
				$candidate['displayName'],
				$this->l10n->t('Stuck · %s', [$candidate['openingTitle']]),
				$this->candidateUrl($candidate['id']),
				$icon,
			);
		}
		$mailError = $today['mailbox']['status']['error'] ?? null;
		if (($today['mailbox']['enabled'] ?? false) && is_string($mailError) && $mailError !== '') {
			$items[] = new WidgetItem(
				$this->l10n->t('The last mailbox check failed'),
				$mailError,
				$this->appUrl() . '#/triage',
				$icon,
			);
		}
		if (($today['triageCount'] ?? 0) > 0) {
			$items[] = new WidgetItem(
				$this->l10n->n('%n application in the triage inbox', '%n applications in the triage inbox', (int)$today['triageCount']),
				'',
				$this->appUrl() . '#/triage',
				$icon,
			);
		}

		return new WidgetItems(
			array_slice($items, 0, max(1, $limit)),
			$this->l10n->t('Nothing is waiting for you'),
		);
	}

	private function appUrl(): string {
		return $this->urlGenerator->linkToRouteAbsolute('recruiting.page.index');
	}

	private function candidateUrl(int $id): string {
		return $this->appUrl() . '#/candidate/' . $id;
	}
}
