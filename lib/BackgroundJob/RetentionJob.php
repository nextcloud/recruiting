<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\BackgroundJob;

use OCA\Recruiting\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily GDPR housekeeping: anonymize candidates whose retention period is
 * over (spec §4.8).
 */
class RetentionJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private RetentionService $retention,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument): void {
		try {
			$result = $this->retention->run();
			if ($result['anonymized'] > 0) {
				$this->logger->info('Recruiting retention: anonymized ' . $result['anonymized'] . ' candidate(s)');
			}
		} catch (\Exception $e) {
			$this->logger->error('Recruiting retention job failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
