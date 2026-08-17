<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Listener;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Service\AiService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use Psr\Log\LoggerInterface;

/**
 * Consumes finished TaskProcessing tasks: intake extraction and summaries
 * are persisted on the candidate (spec §4.7).
 *
 * @template-implements IEventListener<TaskSuccessfulEvent|TaskFailedEvent>
 */
class AiTaskListener implements IEventListener {
	public function __construct(
		private AiService $ai,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof TaskSuccessfulEvent) {
			$task = $event->getTask();
			if ($task->getAppId() !== Application::APP_ID) {
				return;
			}
			try {
				$this->ai->applyTaskResult($task);
			} catch (\Exception $e) {
				$this->logger->warning('Applying AI task result failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		} elseif ($event instanceof TaskFailedEvent) {
			$task = $event->getTask();
			if ($task->getAppId() === Application::APP_ID) {
				$this->logger->info('Recruiting AI task failed: ' . $event->getErrorMessage());
			}
		}
	}
}
