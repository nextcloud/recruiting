<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Opening;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Daily cap on automatic acknowledgements per opening, so neither the public
 * form nor the mailbox can be turned into a mail relay. When the cap is hit,
 * the HR group gets one notification per opening and day.
 */
class AutoAckGuard {
	public function __construct(
		private ConfigService $config,
		private NotificationService $notifications,
		private IGroupManager $groupManager,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reserve one acknowledgement for today. False when the cap is reached.
	 */
	public function tryAcquire(Opening $opening): bool {
		$cap = $this->config->getAutoAckDailyCap();
		if ($cap === 0) {
			return true;
		}
		$openingId = $opening->getId();
		$today = $this->timeFactory->getDateTime()->format('Y-m-d');
		$counter = $this->config->getAutoAckCounter($openingId);
		if ($counter['date'] !== $today) {
			$counter = ['date' => $today, 'count' => 0, 'notified' => false];
		}

		if ($counter['count'] < $cap) {
			$counter['count']++;
			$this->config->setAutoAckCounter($openingId, $counter);
			return true;
		}

		$this->logger->info('Recruiting: daily auto-acknowledgement cap of ' . $cap . ' reached for opening ' . $openingId . ', not sending');
		if (!$counter['notified']) {
			$counter['notified'] = true;
			$this->config->setAutoAckCounter($openingId, $counter);
			$this->notifications->notifyAutoAckCapReached($this->recruiters(), $opening, $cap);
		}
		return false;
	}

	/**
	 * @return string[]
	 */
	private function recruiters(): array {
		$group = $this->groupManager->get($this->config->getHrGroup());
		if ($group === null) {
			return [];
		}
		return array_map(static fn ($user): string => $user->getUID(), $group->getUsers());
	}
}
