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
 * interviewer never finds candidates outside their assignments (spec §4.12),
 * and anonymized candidates are never found.
 */
class RecruitingSearchProvider implements IProvider {
	private const MAX_OPENINGS = 3;

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

	/**
	 * Openings come first, on the first page only; candidates follow and
	 * page through the cursor (an offset into the candidate matches). Both
	 * are filtered by role in SQL, so a page is never emptied by matches
	 * the user may not see; canViewCandidate stays as the final check.
	 */
	#[\Override]
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$uid = $user->getUID();
		$term = trim($query->getTerm());
		if ($term === '') {
			return SearchResult::complete($this->getName(), []);
		}
		$limit = max(1, $query->getLimit());
		$cursor = $query->getCursor();
		$firstPage = $cursor === null || $cursor === '';
		$offset = $firstPage ? 0 : max(0, (int)$cursor);

		$scope = $this->permissions->candidateScope($uid);
		$base = $this->urlGenerator->linkToRouteAbsolute('recruiting.page.index');
		$icon = $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
		$entries = [];

		if ($firstPage) {
			$openings = $this->openingMapper->searchByTitle(
				$term,
				$scope->everything ? null : $scope->visibleOpeningIds(),
				min(self::MAX_OPENINGS, $limit),
			);
			foreach ($openings as $opening) {
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
		}

		$candidateLimit = max(0, $limit - count($entries));
		$rows = $this->candidateMapper->search($term, $scope, $candidateLimit + 1, $offset);
		$hasMore = count($rows) > $candidateLimit;
		foreach (array_slice($rows, 0, $candidateLimit) as $candidate) {
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

		if ($hasMore) {
			return SearchResult::paginated($this->getName(), $entries, $offset + $candidateLimit);
		}
		return SearchResult::complete($this->getName(), $entries);
	}
}
