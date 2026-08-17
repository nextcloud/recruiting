<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCP\Activity\IManager as IActivityManager;
use Psr\Log\LoggerInterface;

/**
 * Publishes entries to the Activity app for everyone involved with a
 * candidate: the opening's team and the assigned screeners.
 *
 * The activity stream is a *published* surface, so it follows the same
 * confidentiality rules as the candidate view: an entry only reaches people
 * who may see that candidate, and entries about offers or outgoing mail only
 * reach people who may see offer data (spec §4.6/§10.4). Otherwise the feed
 * would announce "an offer was sent to X" to the very roles the app promises
 * never learn about salaries.
 */
class ActivityPublisher {
	public const TYPE_RECRUITING = 'recruiting';

	/** Only people who may see offer data receive these */
	private const OFFER_SUBJECTS = [
		'offer_created',
		'offer_approved',
		'offer_sent',
		'offer_accepted',
		'offer_declined',
		'offer_expired',
	];

	public function __construct(
		private IActivityManager $activityManager,
		private TeamMemberMapper $teamMapper,
		private AssignmentMapper $assignmentMapper,
		private PermissionService $permissions,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $parameters
	 */
	public function publish(Candidate $candidate, string $subject, ?string $actorUid, array $parameters = []): void {
		$parameters['candidate'] = $candidate->getDisplayName();
		$parameters['candidateId'] = $candidate->getId();
		$parameters['openingId'] = $candidate->getOpeningId();

		// An offer mail's subject line states what it is about, so it never
		// travels with the event — the feed only says that mail was sent.
		unset($parameters['subject']);
		$offerRelated = in_array($subject, self::OFFER_SUBJECTS, true);

		try {
			foreach ($this->audienceFor($candidate, $actorUid, $offerRelated) as $uid) {
				$event = $this->activityManager->generateEvent();
				$event->setApp(Application::APP_ID)
					->setType(self::TYPE_RECRUITING)
					->setAuthor($actorUid ?? '')
					->setObject('candidate', $candidate->getId())
					->setSubject($subject, $parameters)
					->setAffectedUser($uid);
				$this->activityManager->publish($event);
			}
		} catch (\Exception $e) {
			// Activity is best-effort and must never break the main operation
			$this->logger->warning('Failed to publish recruiting activity: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Everyone involved who is actually allowed to see this candidate — and,
	 * for offer entries, allowed to see offer data.
	 *
	 * @return string[]
	 */
	private function audienceFor(Candidate $candidate, ?string $actorUid, bool $offerRelated): array {
		$involved = array_map(
			static fn ($assignment) => $assignment->getUid(),
			$this->assignmentMapper->findForCandidate($candidate->getId()),
		);
		$openingId = $candidate->getOpeningId();
		if ($openingId !== null) {
			foreach ($this->teamMapper->findForOpening($openingId) as $member) {
				$involved[] = $member->getUid();
			}
		}
		if ($actorUid !== null && $actorUid !== '') {
			$involved[] = $actorUid;
		}

		$audience = [];
		foreach (array_unique($involved) as $uid) {
			if ($uid === '') {
				continue;
			}
			if ($offerRelated
				? $this->permissions->canSeeOffers($uid, $candidate)
				: $this->permissions->canViewCandidate($uid, $candidate)) {
				$audience[] = $uid;
			}
		}
		return $audience;
	}
}
