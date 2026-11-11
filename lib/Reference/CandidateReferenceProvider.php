<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Reference;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Service\CandidateStatus;
use OCA\Recruiting\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\IReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Link previews for candidate links pasted into Talk, Text and other
 * apps (".../apps/recruiting/#/candidate/42"): name, opening and stage —
 * and only for users who may view that candidate. Everyone else gets no
 * rich data at all, not even a hint that the candidate exists.
 */
class CandidateReferenceProvider implements IReferenceProvider {
	public function __construct(
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private PermissionService $permissions,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
	) {
	}

	#[\Override]
	public function matchReference(string $referenceText): bool {
		return $this->candidateId($referenceText) !== null;
	}

	#[\Override]
	public function resolveReference(string $referenceText): ?IReference {
		$id = $this->candidateId($referenceText);
		$uid = $this->userSession->getUser()?->getUID();
		if ($id === null || $uid === null) {
			return null;
		}
		try {
			$candidate = $this->candidateMapper->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
		if (!$this->permissions->canViewCandidate($uid, $candidate)) {
			return null;
		}

		$openingTitle = $this->openingMapper->findTitle($candidate->getOpeningId()) ?? $this->l10n->t('Triage inbox');
		$reference = new Reference($referenceText);
		$reference->setTitle($candidate->getDisplayName());
		$reference->setDescription($this->l10n->t('%1$s · %2$s', [$openingTitle, CandidateStatus::stageLabel($candidate->getStage(), $this->l10n)]));
		$reference->setImageUrl($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));
		$reference->setUrl($referenceText);
		return $reference;
	}

	/**
	 * Cached per candidate (so it can be dropped as a group) …
	 */
	#[\Override]
	public function getCachePrefix(string $referenceId): string {
		$id = $this->candidateId($referenceId);
		return $id !== null ? 'candidate-' . $id : $referenceId;
	}

	/**
	 * … and per user, because who may see the candidate differs per user.
	 */
	#[\Override]
	public function getCacheKey(string $referenceId): ?string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/**
	 * The candidate id of a candidate deep link into this instance, with or
	 * without "index.php" in the URL.
	 */
	private function candidateId(string $url): ?int {
		$base = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		$pattern = '#^' . preg_quote($base, '#') . '/(?:index\.php/)?apps/' . Application::APP_ID . '/?\#/candidate/(\d+)/?$#';
		if (preg_match($pattern, trim($url), $m) !== 1) {
			return null;
		}
		return (int)$m[1];
	}
}
