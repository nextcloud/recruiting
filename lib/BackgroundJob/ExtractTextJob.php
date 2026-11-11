<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\BackgroundJob;

use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Service\AiService;
use OCA\Recruiting\Service\DocumentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Extracts the text of newly stored documents outside the request or mail
 * intake that stored them. For a new application it then schedules the AI
 * intake analysis, which needs the CV text.
 *
 * Argument: {documentIds: int[], intakeCandidateId: ?int}
 */
class ExtractTextJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private DocumentService $documents,
		private CandidateMapper $candidateMapper,
		private AiService $ai,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument): void {
		$argument = is_array($argument) ? $argument : [];
		foreach ((array)($argument['documentIds'] ?? []) as $documentId) {
			try {
				$this->documents->extractStoredText((int)$documentId);
			} catch (\Exception $e) {
				$this->logger->warning('Recruiting: text extraction of document ' . (int)$documentId . ' failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		}

		$candidateId = $argument['intakeCandidateId'] ?? null;
		if ($candidateId === null) {
			return;
		}
		try {
			$candidate = $this->candidateMapper->find((int)$candidateId);
		} catch (DoesNotExistException) {
			return;
		}
		if ($candidate->getAnonymizedAt() === null) {
			$this->ai->scheduleIntakeAnalysis($candidate);
		}
	}
}
