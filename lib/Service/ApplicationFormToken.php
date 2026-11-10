<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * The signed fill-time token of the public application form:
 * "<unix time>.<HMAC-SHA256(time|slug)>" with a per-instance app secret.
 * It proves when (and for which opening) the form was rendered, so a bot
 * can neither skip the minimum fill time nor forge a timestamp.
 */
class ApplicationFormToken {
	public const VALID = 'valid';
	public const INVALID = 'invalid';
	public const TOO_FAST = 'too_fast';
	public const EXPIRED = 'expired';

	public const MIN_FILL_SECONDS = 3;
	public const MAX_AGE_SECONDS = 24 * 3600;

	private const SECRET_KEY = 'public_form_secret';

	public function __construct(
		private IAppConfig $appConfig,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
	) {
	}

	public function issue(string $slug): string {
		$time = (string)$this->timeFactory->getTime();
		return $time . '.' . $this->sign($time, $slug);
	}

	/**
	 * @return self::VALID|self::INVALID|self::TOO_FAST|self::EXPIRED
	 */
	public function check(string $token, string $slug): string {
		if (!preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $token, $m)) {
			return self::INVALID;
		}
		if (!hash_equals($this->sign($m[1], $slug), $m[2])) {
			return self::INVALID;
		}
		$age = $this->timeFactory->getTime() - (int)$m[1];
		if ($age < self::MIN_FILL_SECONDS) {
			return self::TOO_FAST;
		}
		if ($age > self::MAX_AGE_SECONDS) {
			return self::EXPIRED;
		}
		return self::VALID;
	}

	private function sign(string $time, string $slug): string {
		return hash_hmac('sha256', $time . '|' . $slug, $this->secret());
	}

	private function secret(): string {
		$secret = $this->appConfig->getValueString(Application::APP_ID, self::SECRET_KEY, '', lazy: true);
		if ($secret === '') {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, self::SECRET_KEY, $secret, lazy: true, sensitive: true);
		}
		return $secret;
	}
}
