<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\NotPermittedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * The consent-based talent pool (spec §4.8): candidates opt in via a public
 * link in their rejection mail. Only then does the pool membership exist,
 * and it expires on its own schedule.
 */
class PoolService {
	public function __construct(
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private PermissionService $permissions,
		private TimelineService $timeline,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Create (or reuse) the consent token and return the public URL that
	 * goes into the rejection mail.
	 */
	public function prepareConsentUrl(Candidate $candidate, ?string $actorUid): string {
		if ($candidate->getPoolConsentToken() === null || $candidate->getPoolConsentToken() === '') {
			$candidate->setPoolConsentToken($this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC));
			$this->candidateMapper->update($candidate);
			$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_POOL_INVITED, $actorUid);
		}
		return $this->urlGenerator->linkToRouteAbsolute('recruiting.public.showPoolConsent', [
			'token' => $candidate->getPoolConsentToken(),
		]);
	}

	public function findByToken(string $token): ?Candidate {
		if (strlen($token) < 32 || !ctype_alnum($token)) {
			return null;
		}
		$candidate = $this->candidateMapper->findByPoolToken($token);
		if ($candidate !== null && $candidate->getAnonymizedAt() !== null) {
			return null;
		}
		return $candidate;
	}

	/**
	 * The candidate clicked the consent link: membership starts now, the
	 * retention clock restarts (spec §4.8).
	 */
	public function confirm(Candidate $candidate): void {
		if ($candidate->getPoolMember()) {
			return;
		}
		$candidate->setPoolMember(true);
		$candidate->setPoolConsentAt($this->timeFactory->getDateTime());
		$this->candidateMapper->update($candidate);
		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_POOL_JOINED, null);
	}

	/**
	 * Pool listing for recruiters.
	 *
	 * @throws NotPermittedException
	 */
	public function listMembers(string $uid): array {
		$this->permissions->assertRecruiter($uid);
		$result = [];
		foreach ($this->candidateMapper->findPoolMembers() as $candidate) {
			$entry = $candidate->jsonSerialize();
			$entry['openingTitle'] = $this->openingMapper->findTitle($candidate->getOpeningId());
			$result[] = $entry;
		}
		return $result;
	}

}
