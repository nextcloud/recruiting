<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\BackgroundJob;

use OCA\Recruiting\Service\OfferService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily offer housekeeping: warn creators three days before an offer
 * expires, mark overdue offers expired (spec §4.6).
 */
class OfferExpiryJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OfferService $offers,
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
			$this->offers->processExpiry();
		} catch (\Exception $e) {
			$this->logger->error('Recruiting offer expiry job failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
