<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\IngestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Instance-wide configuration. All endpoints are admin-only (no
 * NoAdminRequired attribute).
 */
class SettingsController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $config,
		private IngestionService $ingestion,
		private IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	public function get(): DataResponse {
		return $this->handle(fn () => $this->settingsPayload());
	}

	public function update(): DataResponse {
		return $this->handle(function () {
			$params = $this->request->getParams();
			if (array_key_exists('hrGroup', $params)) {
				$gid = (string)$params['hrGroup'];
				if ($gid !== '' && !$this->groupManager->groupExists($gid)) {
					throw new ValidationException('This group does not exist');
				}
				$this->config->setHrGroup($gid);
			}
			if (array_key_exists('senderName', $params)) {
				$this->config->setSenderName(trim((string)$params['senderName']));
			}
			if (array_key_exists('senderEmail', $params)) {
				$email = trim((string)$params['senderEmail']);
				if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
					throw new ValidationException('The sender address is invalid');
				}
				$this->config->setSenderEmail($email);
			}
			if (array_key_exists('retentionDays', $params)) {
				$this->config->setRetentionDays((int)$params['retentionDays']);
			}
			if (array_key_exists('poolRetentionMonths', $params)) {
				$this->config->setPoolRetentionMonths((int)$params['poolRetentionMonths']);
			}
			if (array_key_exists('imapEnabled', $params)) {
				$this->config->setImapEnabled((bool)$params['imapEnabled']);
			}
			if (array_key_exists('imapHost', $params)) {
				$this->config->setImapConfig(
					(string)($params['imapHost'] ?? ''),
					max(1, min(65535, (int)($params['imapPort'] ?? 993))),
					(string)($params['imapSecurity'] ?? 'ssl'),
					(string)($params['imapUser'] ?? ''),
					// null = unchanged; the UI sends the field only when edited
					array_key_exists('imapPassword', $params) ? (string)$params['imapPassword'] : null,
				);
			}
			return $this->settingsPayload();
		});
	}

	public function testImap(): DataResponse {
		return $this->handle(function () {
			$params = $this->request->getParams();
			$stored = $this->config->getImapConfig();
			$password = array_key_exists('imapPassword', $params) && (string)$params['imapPassword'] !== ''
				? (string)$params['imapPassword']
				: $stored['password'];
			try {
				$this->ingestion->testConnection(
					(string)($params['imapHost'] ?? $stored['host']),
					max(1, min(65535, (int)($params['imapPort'] ?? $stored['port']))),
					(string)($params['imapSecurity'] ?? $stored['security']),
					(string)($params['imapUser'] ?? $stored['user']),
					$password,
				);
			} catch (\Exception $e) {
				throw new ValidationException($e->getMessage());
			}
			return ['ok' => true];
		});
	}

	public function groups(): DataResponse {
		return $this->handle(function () {
			$groups = [];
			foreach ($this->groupManager->search('') as $group) {
				$groups[] = ['gid' => $group->getGID(), 'displayName' => $group->getDisplayName()];
			}
			return $groups;
		});
	}

	private function settingsPayload(): array {
		$imap = $this->config->getImapConfig();
		return [
			'hrGroup' => $this->config->getHrGroup(),
			'senderName' => $this->config->getSenderName(),
			'senderEmail' => $this->config->getSenderEmail(),
			'retentionDays' => $this->config->getRetentionDays(),
			'poolRetentionMonths' => $this->config->getPoolRetentionMonths(),
			'imapEnabled' => $this->config->isImapEnabled(),
			'imapHost' => $imap['host'],
			'imapPort' => $imap['port'],
			'imapSecurity' => $imap['security'],
			'imapUser' => $imap['user'],
			'imapPasswordSet' => $imap['password'] !== '',
		];
	}
}
