<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\BackgroundJob;

use OCA\Recruiting\Service\IngestionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Polls the applications mailbox every five minutes (spec §6).
 */
class MailFetchJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private IngestionService $ingestion,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument): void {
		try {
			$this->ingestion->fetchNow();
		} catch (\Exception $e) {
			$this->logger->error('Recruiting mail fetch failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
