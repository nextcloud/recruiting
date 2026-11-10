<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ICrypto;

/**
 * Typed access to all app-level configuration. The IMAP password is
 * encrypted at rest with the instance secret.
 */
class ConfigService {
	public const DEFAULT_HR_GROUP = 'recruiting';
	public const MAX_DOCUMENT_SIZE = 20 * 1024 * 1024;
	public const DEFAULT_AUTO_ACK_DAILY_CAP = 100;

	/** Mime types accepted for candidate documents */
	public const ALLOWED_MIMES = [
		'application/pdf',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.oasis.opendocument.text',
		'text/plain',
		'text/markdown',
		'image/png',
		'image/jpeg',
		'image/webp',
	];

	public function __construct(
		private IAppConfig $appConfig,
		private ICrypto $crypto,
	) {
	}

	public function getHrGroup(): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, 'hr_group', self::DEFAULT_HR_GROUP);
		return $value === '' ? self::DEFAULT_HR_GROUP : $value;
	}

	public function setHrGroup(string $gid): void {
		$this->appConfig->setValueString(Application::APP_ID, 'hr_group', $gid);
	}

	public function getSenderName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'sender_name', '');
	}

	public function setSenderName(string $name): void {
		$this->appConfig->setValueString(Application::APP_ID, 'sender_name', $name);
	}

	public function getSenderEmail(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'sender_email', '');
	}

	public function setSenderEmail(string $email): void {
		$this->appConfig->setValueString(Application::APP_ID, 'sender_email', $email);
	}

	/**
	 * Days after a rejection/withdrawal until candidate data is anonymized
	 * automatically. 0 disables the automation (spec §4.8).
	 */
	public function getRetentionDays(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'retention_days', 180);
	}

	public function setRetentionDays(int $days): void {
		$this->appConfig->setValueInt(Application::APP_ID, 'retention_days', max(0, min(3650, $days)));
	}

	/**
	 * Months a talent-pool membership stays valid after the candidate's
	 * consent, before automatic anonymization (spec §4.8).
	 */
	public function getPoolRetentionMonths(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'pool_retention_months', 12);
	}

	public function setPoolRetentionMonths(int $months): void {
		$this->appConfig->setValueInt(Application::APP_ID, 'pool_retention_months', max(1, min(120, $months)));
	}

	/**
	 * Automatic acknowledgements per opening and day; 0 means unlimited.
	 * Stops the public form and the mailbox from being used as a mail relay.
	 */
	public function getAutoAckDailyCap(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, 'auto_ack_daily_cap', self::DEFAULT_AUTO_ACK_DAILY_CAP));
	}

	public function setAutoAckDailyCap(int $cap): void {
		$this->appConfig->setValueInt(Application::APP_ID, 'auto_ack_daily_cap', max(0, min(100000, $cap)));
	}

	/**
	 * Today's auto-acknowledgement tally of one opening. One lazily loaded
	 * key per opening, overwritten when the date changes.
	 *
	 * @return array{date: string, count: int, notified: bool}
	 */
	public function getAutoAckCounter(int $openingId): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'auto_ack_count_' . $openingId, '', lazy: true);
		$data = $raw !== '' ? json_decode($raw, true) : null;
		return [
			'date' => is_array($data) ? (string)($data['date'] ?? '') : '',
			'count' => is_array($data) ? (int)($data['count'] ?? 0) : 0,
			'notified' => is_array($data) && (bool)($data['notified'] ?? false),
		];
	}

	/**
	 * @param array{date: string, count: int, notified: bool} $counter
	 */
	public function setAutoAckCounter(int $openingId, array $counter): void {
		$this->appConfig->setValueString(Application::APP_ID, 'auto_ack_count_' . $openingId, json_encode($counter) ?: '', lazy: true);
	}

	/**
	 * Result of the last ingestion run — the triage view shows it so a
	 * silently failing mailbox does not go unnoticed. `skipped` counts
	 * automatic mails (bounces, autoresponders) that were not ingested,
	 * `quarantined` the messages currently in `quarantineFolder`.
	 *
	 * @param array{ranAt: string, fetched: int, created: int, skipped: int, quarantined: int, quarantineFolder: string, error: ?string} $status
	 */
	public function setIngestionStatus(array $status): void {
		$this->appConfig->setValueString(Application::APP_ID, 'ingestion_status', json_encode($status) ?: '');
	}

	/**
	 * @return ?array{ranAt?: string, fetched?: int, created?: int, skipped?: int, quarantined?: int, quarantineFolder?: string, error?: ?string}
	 */
	public function getIngestionStatus(): ?array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'ingestion_status', '');
		if ($raw === '') {
			return null;
		}
		$status = json_decode($raw, true);
		return is_array($status) ? $status : null;
	}

	public function isImapEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'imap_enabled', false);
	}

	public function setImapEnabled(bool $enabled): void {
		$this->appConfig->setValueBool(Application::APP_ID, 'imap_enabled', $enabled);
	}

	/**
	 * @return array{host: string, port: int, security: string, user: string, password: string}
	 */
	public function getImapConfig(): array {
		return [
			'host' => $this->appConfig->getValueString(Application::APP_ID, 'imap_host', ''),
			'port' => $this->appConfig->getValueInt(Application::APP_ID, 'imap_port', 993),
			'security' => $this->appConfig->getValueString(Application::APP_ID, 'imap_security', 'ssl'),
			'user' => $this->appConfig->getValueString(Application::APP_ID, 'imap_user', ''),
			'password' => $this->getImapPassword(),
		];
	}

	public function setImapConfig(string $host, int $port, string $security, string $user, ?string $password): void {
		$this->appConfig->setValueString(Application::APP_ID, 'imap_host', trim($host));
		$this->appConfig->setValueInt(Application::APP_ID, 'imap_port', $port);
		$this->appConfig->setValueString(Application::APP_ID, 'imap_security', in_array($security, ['ssl', 'starttls', 'none'], true) ? $security : 'ssl');
		$this->appConfig->setValueString(Application::APP_ID, 'imap_user', trim($user));
		if ($password !== null) {
			$encrypted = $password === '' ? '' : $this->crypto->encrypt($password);
			$this->appConfig->setValueString(Application::APP_ID, 'imap_password', $encrypted, sensitive: true);
		}
	}

	private function getImapPassword(): string {
		$encrypted = $this->appConfig->getValueString(Application::APP_ID, 'imap_password', '');
		if ($encrypted === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($encrypted);
		} catch (\Exception) {
			return '';
		}
	}
}
