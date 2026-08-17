<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Search;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Service\PermissionService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified search over candidates and openings — permission-scoped, so an
 * interviewer never finds candidates outside their assignments (spec §4.12).
 */
class RecruitingSearchProvider implements IProvider {
	public function __construct(
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private PermissionService $permissions,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
	) {
	}

	#[\Override]
	public function getId(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Recruiting');
	}

	#[\Override]
	public function getOrder(string $route, array $routeParameters): int {
		return str_starts_with($route, 'recruiting.') ? -1 : 60;
	}

	#[\Override]
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$uid = $user->getUID();
		$term = $query->getTerm();
		$entries = [];
		$base = $this->urlGenerator->linkToRouteAbsolute('recruiting.page.index');
		$icon = $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');

		foreach ($this->candidateMapper->search($term, 30) as $candidate) {
			if (count($entries) >= 8) {
				break;
			}
			if (!$this->permissions->canViewCandidate($uid, $candidate)) {
				continue;
			}
			$entries[] = new SearchResultEntry(
				'',
				$candidate->getDisplayName(),
				$candidate->getEmail(),
				$base . '#/candidate/' . $candidate->getId(),
				$icon,
			);
		}

		$needle = mb_strtolower($term);
		foreach ($this->openingMapper->findAll() as $opening) {
			if (count($entries) >= 12) {
				break;
			}
			if (!str_contains(mb_strtolower($opening->getTitle()), $needle)) {
				continue;
			}
			if (!$this->permissions->canViewOpening($uid, $opening->getId())) {
				continue;
			}
			$entries[] = new SearchResultEntry(
				'',
				$opening->getTitle(),
				$this->l10n->t('Job opening'),
				$base . '#/openings/' . $opening->getId(),
				$icon,
			);
		}

		return SearchResult::complete($this->getName(), $entries);
	}
}
