<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Command;

use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\IngestionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FetchMail extends Command {
	public function __construct(
		private IngestionService $ingestion,
		private ConfigService $config,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('recruiting:fetch-mail')
			->setDescription('Fetch the applications mailbox now and ingest new candidates');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->config->isImapEnabled()) {
			$output->writeln('<comment>Mail ingestion is disabled — enable it in the admin settings first.</comment>');
			return self::SUCCESS;
		}
		try {
			$stats = $this->ingestion->fetchNow();
		} catch (\Exception $e) {
			$output->writeln('<error>Fetching failed: ' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}
		$output->writeln(sprintf('Fetched %d message(s), created %d candidate(s).', $stats['fetched'], $stats['created']));
		return self::SUCCESS;
	}
}
