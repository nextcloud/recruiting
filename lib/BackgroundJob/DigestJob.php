<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\BackgroundJob;

use OCA\Recruiting\Service\DigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly check: send every digest whose slot is due (spec §4.9).
 */
class DigestJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private DigestService $digest,
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
			$this->digest->sendDue();
		} catch (\Exception $e) {
			$this->logger->error('Recruiting digest job failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
