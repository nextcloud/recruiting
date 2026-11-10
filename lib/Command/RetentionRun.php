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
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report who would be anonymized or deleted');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$result = $this->retention->run($dryRun);

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
		return self::SUCCESS;
	}
}
