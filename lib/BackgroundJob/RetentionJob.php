<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\BackgroundJob;

use OCA\Recruiting\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * GDPR housekeeping: anonymize or delete candidates whose retention period
 * is over (spec §4.8, RetentionService). Each run handles one batch; runs
 * are hourly so a backlog is worked off, and eligibility depends only on
 * dates, so a run with nothing due costs only the four lookups.
 */
class RetentionJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private RetentionService $retention,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument): void {
		try {
			$result = $this->retention->run();
			if ($result['anonymized'] > 0 || $result['deleted'] > 0) {
				$this->logger->info('Recruiting retention: anonymized ' . $result['anonymized'] . ', deleted ' . $result['deleted'] . ' candidate(s)'
					. ($result['complete'] ? '' : '; more are due and follow in the next run'));
			}
		} catch (\Exception $e) {
			$this->logger->error('Recruiting retention job failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
