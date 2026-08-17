<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Activity;

use OCA\Recruiting\Service\ActivityPublisher;
use OCP\Activity\ISetting;
use OCP\IL10N;

class Setting implements ISetting {
	public function __construct(
		private IL10N $l,
	) {
	}

	#[\Override]
	public function getIdentifier(): string {
		return ActivityPublisher::TYPE_RECRUITING;
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Job applications, screening votes, interviews and candidate mails');
	}

	#[\Override]
	public function getPriority(): int {
		return 60;
	}

	#[\Override]
	public function canChangeStream(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledStream(): bool {
		return true;
	}

	#[\Override]
	public function canChangeMail(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
