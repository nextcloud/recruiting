<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Command;

use OCA\Recruiting\Service\DocumentService;
use OCP\Console\Attribute\AsCommand;
use OCP\Console\Attribute\Option;
use OCP\Console\ExitCode;
use OCP\Console\IOutput;

#[AsCommand(
	name: 'recruiting:documents:extract-text',
	description: 'Extract the text of stored candidate documents (CVs) for AI material and search',
	help: 'New uploads are extracted automatically. This fills in documents stored before text extraction existed; documents without readable text are skipped.',
)]
class ExtractDocumentText {
	private const BATCH_SIZE = 100;

	public function __construct(
		private DocumentService $documents,
	) {
	}

	public function __invoke(
		IOutput $output,
		#[Option(description: 'Stop after this many documents (0 = all)')]
		int $limit = 0,
	): ExitCode {
		$afterId = 0;
		$checked = 0;
		$extracted = 0;
		do {
			$batch = $limit > 0 ? min(self::BATCH_SIZE, $limit - $checked) : self::BATCH_SIZE;
			$result = $this->documents->backfillText($afterId, $batch);
			$afterId = $result['lastId'];
			$checked += $result['checked'];
			$extracted += $result['extracted'];
		} while ($result['checked'] === $batch && ($limit === 0 || $checked < $limit));

		$output->writeln(sprintf('Checked %d document(s), extracted text from %d.', $checked, $extracted));
		return ExitCode::Success;
	}
}
