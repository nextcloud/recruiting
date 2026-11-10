<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Scans candidate documents with the files_antivirus app before they are
 * stored (soft dependency: without the app nothing is scanned).
 *
 * files_antivirus only covers app data by accident: its storage wrapper is
 * registered when a *user's* filesystem is set up, so writes from the public
 * application form and from the mail fetch job (no user) go unscanned, and
 * its background scanner skips `appdata_*` explicitly. Hence the explicit
 * scan here, through the app's own scanner and configuration.
 *
 * A scanner that cannot be reached or reports the content as unscannable
 * does not block the upload; that is logged instead (the same default as
 * files_antivirus without "block unreachable/unscannable").
 */
class VirusScanService {
	public const APP_ID = 'files_antivirus';
	private const SCANNER_FACTORY = 'OCA\\Files_Antivirus\\Scanner\\ScannerFactory';

	/** OCA\Files_Antivirus\Status codes */
	private const STATUS_CLEAN = 0;
	private const STATUS_INFECTED = 1;

	public function __construct(
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return ?string what the scanner found when the content is infected,
	 *                 null when it is clean or could not be scanned
	 */
	public function findThreat(string $content): ?string {
		$scanner = $this->scanner(strlen($content));
		if ($scanner === null) {
			return null;
		}
		try {
			$status = $scanner->scanString($content);
			$code = (int)$status->getNumericStatus();
			$details = (string)$status->getDetails();
		} catch (\Throwable $e) {
			$this->logger->warning('Virus scan of a candidate document failed, document stored unscanned: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
		if ($code === self::STATUS_INFECTED) {
			return $details !== '' ? $details : 'unknown threat';
		}
		if ($code !== self::STATUS_CLEAN) {
			$this->logger->warning('Candidate document could not be scanned for viruses (status ' . $code . '), stored anyway');
		}
		return null;
	}

	/**
	 * The scanner of files_antivirus, configured from its admin settings.
	 * Resolved through the server container so the app's own container (and
	 * with it its configuration) is used.
	 */
	protected function scanner(int $size): ?object {
		if (!$this->appManager->isEnabledForAnyone(self::APP_ID) || !class_exists(self::SCANNER_FACTORY)) {
			return null;
		}
		try {
			$factory = \OCP\Server::get(self::SCANNER_FACTORY);
			/** @var object $scanner */
			$scanner = $factory->getScanner(null, $size);
			return $scanner;
		} catch (\Throwable $e) {
			$this->logger->warning('files_antivirus is enabled but its scanner is unavailable, document stored unscanned: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}
}
