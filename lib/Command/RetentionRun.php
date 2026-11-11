<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Command;

use OCA\Recruiting\Service\RetentionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RetentionRun extends Command {
	public function __construct(
		private RetentionService $retention,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('recruiting:retention:run')
			->setDescription('Anonymize or delete candidates whose retention period is over')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report who would be anonymized or deleted')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Handle at most this many candidates (0 = all; the background job handles ' . RetentionService::BATCH_SIZE . ' per run). Ignored by --dry-run, which always lists everyone due', '0');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$limit = $input->getOption('limit');
		if (!is_numeric($limit) || (int)$limit < 0) {
			$output->writeln('<error>--limit must be 0 or a positive number.</error>');
			return self::INVALID;
		}
		$result = $this->retention->run($dryRun, (int)$limit);

		foreach ($result['groups'] as $group) {
			$delete = $group['action'] === RetentionService::ACTION_DELETE;
			if (!$group['enabled']) {
				$output->writeln(sprintf('<comment>%s: disabled (period is 0 days).</comment>', $group['label']));
				continue;
			}
			$verb = $dryRun
				? ($delete ? 'Would delete' : 'Would anonymize')
				: ($delete ? 'Deleted' : 'Anonymized');
			$output->writeln(sprintf('%s: %s %d candidate(s), %s.', $group['label'], strtolower($verb), count($group['names']), $group['reason']));
			foreach ($group['names'] as $name) {
				$output->writeln('  - ' . $name);
			}
		}
		$output->writeln(sprintf(
			'%s: %d anonymized, %d deleted.',
			$dryRun ? 'Total (dry run)' : 'Total',
			$result['anonymized'],
			$result['deleted'],
		));
		if (!$result['complete']) {
			$output->writeln('<comment>The limit was reached; more candidates may be due. Run again to continue.</comment>');
		}
		return self::SUCCESS;
	}
}
