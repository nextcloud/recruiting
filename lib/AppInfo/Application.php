<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\AppInfo;

use OCA\Recruiting\Listener\AiTaskListener;
use OCA\Recruiting\Listener\UserDeletedListener;
use OCA\Recruiting\Notification\Notifier;
use OCA\Recruiting\Search\RecruitingSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'recruiting';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);
		$context->registerSearchProvider(RecruitingSearchProvider::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(TaskSuccessfulEvent::class, AiTaskListener::class);
		$context->registerEventListener(TaskFailedEvent::class, AiTaskListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
