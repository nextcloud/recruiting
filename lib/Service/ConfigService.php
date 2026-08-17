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
	 * Result of the last ingestion run — the triage view shows it so a
	 * silently failing mailbox does not go unnoticed.
	 *
	 * @param array{ranAt: string, fetched: int, created: int, error: ?string} $status
	 */
	public function setIngestionStatus(array $status): void {
		$this->appConfig->setValueString(Application::APP_ID, 'ingestion_status', json_encode($status) ?: '');
	}

	/**
	 * @return ?array{ranAt: string, fetched: int, created: int, error: ?string}
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
