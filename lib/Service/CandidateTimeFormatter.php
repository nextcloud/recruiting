<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCP\Config\IUserConfig;
use OCP\IL10N;

/**
 * Interview times for server-rendered candidate-facing text (mails, the
 * self-service page), where the candidate's own timezone is unknown: the
 * organizer's timezone, spelled out, in the same long form the public
 * interview page uses ("Tuesday, November 10, 2026" + "2:00 PM").
 */
class CandidateTimeFormatter {
	public function __construct(
		private IUserConfig $userConfig,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param ?IL10N $l the candidate's language; the request's when null
	 * @return array{date: string, time: string}
	 */
	public function format(\DateTimeInterface $at, ?string $organizerUid, ?IL10N $l = null): array {
		$l ??= $this->l10n;
		$timezone = $organizerUid !== null && $organizerUid !== ''
			? $this->userConfig->getValueString($organizerUid, 'core', 'timezone', '')
			: '';
		try {
			$zone = new \DateTimeZone($timezone !== '' ? $timezone : date_default_timezone_get());
		} catch (\Exception) {
			$zone = new \DateTimeZone('UTC');
		}
		$local = \DateTime::createFromInterface($at)->setTimezone($zone);
		return [
			'date' => (string)$l->l('date', $local, ['width' => 'full']),
			'time' => (string)$l->l('time', $local, ['width' => 'short']) . ' (' . $zone->getName() . ')',
		];
	}
}
