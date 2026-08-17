<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Command;

use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\RetentionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RetentionRun extends Command {
	public function __construct(
		private RetentionService $retention,
		private ConfigService $config,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('recruiting:retention:run')
			->setDescription('Anonymize candidates whose retention period is over')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report who would be anonymized');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$days = $this->config->getRetentionDays();
		if ($days <= 0) {
			$output->writeln('<comment>Retention automation is disabled (retention period is 0 days).</comment>');
			return self::SUCCESS;
		}
		$dryRun = (bool)$input->getOption('dry-run');
		$result = $this->retention->run($dryRun);
		$verb = $dryRun ? 'Would anonymize' : 'Anonymized';
		$output->writeln(sprintf('%s %d candidate(s) (retention period: %d days).', $verb, $result['anonymized'], $days));
		foreach ($result['names'] as $name) {
			$output->writeln('  - ' . $name);
		}
		return self::SUCCESS;
	}
}
