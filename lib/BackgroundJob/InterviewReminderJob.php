<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\BackgroundJob;

use OCA\Recruiting\Service\InterviewReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly: remind candidates of confirmed interviews about 24 hours ahead.
 */
class InterviewReminderJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private InterviewReminderService $reminders,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument): void {
		try {
			$this->reminders->sendDue();
		} catch (\Exception $e) {
			$this->logger->error('Recruiting interview reminder job failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
