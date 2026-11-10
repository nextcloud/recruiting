<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Migration;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Service\ActivityPurgeService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Activity entries written by older versions carry the candidate's name in
 * their stored parameters. The name is resolved at render time now, so the
 * stored copy is removed once; it would otherwise outlive deletion and
 * anonymization in the activity tables.
 */
class StripActivityCandidateNames implements IRepairStep {
	private const DONE_KEY = 'activity_names_stripped';

	public function __construct(
		private ActivityPurgeService $activityPurge,
		private IAppConfig $appConfig,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove stored candidate names from Recruiting activity entries';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool(Application::APP_ID, self::DONE_KEY)) {
			return;
		}
		$rewritten = $this->activityPurge->stripStoredNames();
		$this->appConfig->setValueBool(Application::APP_ID, self::DONE_KEY, true);
		if ($rewritten > 0) {
			$output->info('Removed the candidate name from ' . $rewritten . ' activity entries');
		}
	}
}
