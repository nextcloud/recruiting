<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Talk\IBroker;
use Psr\Log\LoggerInterface;

/**
 * Talk is a soft dependency (spec §4.4): when it is not installed the
 * video option simply is not offered.
 */
class TalkService {
	public function __construct(
		private IBroker $broker,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		try {
			return $this->broker->hasBackend();
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Create a public Talk room for a video interview. Public, so the
	 * candidate can join via the link without an account.
	 *
	 * @param IUser[] $moderators
	 * @return ?array{token: string, url: string}
	 */
	public function createRoom(string $name, array $moderators): ?array {
		if (!$this->isAvailable()) {
			return null;
		}
		try {
			$options = $this->broker->newConversationOptions();
			$options->setPublic();
			$conversation = $this->broker->createConversation($name, $moderators, $options);
			return [
				'token' => $conversation->getId(),
				'url' => $conversation->getAbsoluteUrl(),
			];
		} catch (\Exception $e) {
			$this->logger->warning('Could not create Talk room: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Create a private team conversation for an opening's hiring team.
	 * Members must be set at creation — the public Talk API offers no way
	 * to sync them later, so a team change means recreating the room.
	 *
	 * @param IUser[] $members
	 * @return ?array{token: string, url: string}
	 */
	public function createTeamRoom(string $name, array $members): ?array {
		if (!$this->isAvailable() || $members === []) {
			return null;
		}
		try {
			$conversation = $this->broker->createConversation($name, $members);
			return [
				'token' => $conversation->getId(),
				'url' => $conversation->getAbsoluteUrl(),
			];
		} catch (\Exception $e) {
			$this->logger->warning('Could not create Talk team room: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function urlForToken(string $token): string {
		try {
			return $this->urlGenerator->linkToRouteAbsolute('spreed.Page.showCall', ['token' => $token]);
		} catch (\Exception) {
			return $this->urlGenerator->getAbsoluteURL('/call/' . $token);
		}
	}

	public function deleteRoom(string $token): void {
		if ($token === '' || !$this->isAvailable()) {
			return;
		}
		try {
			$this->broker->deleteConversation($token);
		} catch (\Exception $e) {
			$this->logger->warning('Could not delete Talk room: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
