<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\VoteMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * The optional personal digest mail (spec §4.9): new applications in your
 * openings, your pending reviews, your upcoming interviews and offers
 * awaiting your approval. Off by default; only sent when there is content.
 */
class DigestService {
	public const PREF_KEY = 'digest';
	public const PREF_LAST = 'digest_last_sent';
	public const MODES = ['none', 'daily', 'weekly'];

	public function __construct(
		private IUserConfig $userConfig,
		private IUserManager $userManager,
		private CandidateMapper $candidateMapper,
		private AssignmentMapper $assignmentMapper,
		private TeamMemberMapper $teamMapper,
		private VoteMapper $voteMapper,
		private InterviewMapper $interviewMapper,
		private OfferMapper $offerMapper,
		private PermissionService $permissions,
		private IMailer $mailer,
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	public function getMode(string $uid): string {
		$mode = $this->userConfig->getValueString($uid, Application::APP_ID, self::PREF_KEY, 'none');
		return in_array($mode, self::MODES, true) ? $mode : 'none';
	}

	public function setMode(string $uid, string $mode): void {
		if (!in_array($mode, self::MODES, true)) {
			$mode = 'none';
		}
		$this->userConfig->setValueString($uid, Application::APP_ID, self::PREF_KEY, $mode);
	}

	/**
	 * Hourly entry point: send every due digest.
	 *
	 * @return int number of digests sent
	 */
	public function sendDue(): int {
		$now = $this->timeFactory->getTime();
		$sent = 0;
		foreach (['daily' => 22 * 3600, 'weekly' => (7 * 24 - 4) * 3600] as $mode => $minInterval) {
			foreach ($this->userConfig->searchUsersByValueString(Application::APP_ID, self::PREF_KEY, $mode) as $uid) {
				$last = (int)$this->userConfig->getValueString($uid, Application::APP_ID, self::PREF_LAST, '0');
				if ($now - $last < $minInterval) {
					continue;
				}
				try {
					if ($this->sendFor($uid, $last)) {
						$sent++;
					}
					// An empty digest still closes the window (otherwise we would
					// rebuild it every hour); a failed send does not, so the next
					// run tries again instead of losing the day.
					$this->userConfig->setValueString($uid, Application::APP_ID, self::PREF_LAST, (string)$now);
				} catch (\Exception $e) {
					$this->logger->warning('Recruiting digest for ' . $uid . ' failed, will retry: ' . $e->getMessage(), ['exception' => $e]);
				}
			}
		}
		return $sent;
	}

	/**
	 * @return bool whether a mail went out (false = nothing to report)
	 * @throws \RuntimeException when sending failed — the caller then leaves
	 *                           the digest window open so the next run retries
	 *                           instead of silently swallowing a day
	 */
	public function sendFor(string $uid, int $sinceTimestamp): bool {
		$user = $this->userManager->get($uid);
		$email = $user?->getEMailAddress();
		if ($user === null || $email === null || $email === '') {
			return false;
		}
		$since = $sinceTimestamp > 0
			? (new \DateTimeImmutable('@' . $sinceTimestamp))
			: (new \DateTimeImmutable('@' . $this->timeFactory->getTime()))->modify('-7 days');

		$l = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));
		$sections = $this->buildSections($uid, $since, $l);
		if ($sections === []) {
			return false;
		}

		$template = $this->mailer->createEMailTemplate('recruiting.digest');
		$template->addHeader();
		$template->addHeading($l->t('Your recruiting digest'));
		foreach ($sections as [$title, $lines]) {
			$template->addBodyText($title);
			foreach ($lines as $line) {
				$template->addBodyListItem($line);
			}
		}
		$template->addBodyButton($l->t('Open Recruiting'), $this->urlGenerator->linkToRouteAbsolute('recruiting.page.index'));
		$template->addFooter();
		$template->setSubject($l->t('Recruiting digest'));

		$message = $this->mailer->createMessage();
		$message->setTo([$email => $user->getDisplayName()]);
		$message->useTemplate($template);
		$failed = $this->mailer->send($message);
		if ($failed !== []) {
			throw new \RuntimeException('Digest mail was rejected for ' . implode(', ', $failed));
		}
		return true;
	}

	/**
	 * @return array<int, array{0: string, 1: string[]}>
	 */
	private function buildSections(string $uid, \DateTimeImmutable $since, \OCP\IL10N $l): array {
		$sections = [];
		$now = $this->timeFactory->getDateTime();

		// New applications in openings the user manages (recruiters: all)
		$openingIds = [];
		if ($this->permissions->isRecruiter($uid)) {
			$openingIds = null; // all
		} else {
			foreach ($this->teamMapper->findForUser($uid) as $membership) {
				if ($membership->getRole() === TeamMember::ROLE_MANAGER) {
					$openingIds[] = $membership->getOpeningId();
				}
			}
		}
		$newApplications = array_map(
			static fn ($candidate) => $candidate->getDisplayName(),
			$this->candidateMapper->findCreatedSince(\DateTime::createFromImmutable($since), $openingIds),
		);
		if ($newApplications !== []) {
			$sections[] = [
				$l->n('%n new application', '%n new applications', count($newApplications)),
				array_slice($newApplications, 0, 10),
			];
		}

		// Pending reviews: assigned, active, no vote yet
		$assigned = array_values(array_filter(
			$this->candidateMapper->findByIds($this->assignmentMapper->findCandidateIdsForUser($uid)),
			static fn ($candidate) => !$candidate->isTerminal(),
		));
		$voted = [];
		foreach ($this->voteMapper->findForUser($uid, array_map(static fn ($c) => $c->getId(), $assigned)) as $vote) {
			$voted[$vote->getCandidateId()] = true;
		}
		$pending = [];
		foreach ($assigned as $candidate) {
			if (!isset($voted[$candidate->getId()])) {
				$pending[] = $candidate->getDisplayName();
			}
		}
		if ($pending !== []) {
			$sections[] = [
				$l->n('%n candidate waiting for your review', '%n candidates waiting for your review', count($pending)),
				array_slice($pending, 0, 10),
			];
		}

		// Upcoming interviews (next 7 days)
		$horizon = \DateTime::createFromImmutable(\DateTimeImmutable::createFromInterface($now)->modify('+7 days'));
		$dueInterviews = array_values(array_filter(
			$this->interviewMapper->findUpcomingForUser($uid, $now),
			static fn ($interview) => $interview->getStatus() === Interview::STATUS_CONFIRMED
				&& $interview->getStartAt() !== null
				&& $interview->getStartAt() <= $horizon,
		));
		$upcoming = [];
		$names = $this->namesFor(array_map(static fn ($i) => $i->getCandidateId(), $dueInterviews));
		foreach ($dueInterviews as $interview) {
			$name = $names[$interview->getCandidateId()] ?? null;
			if ($name === null) {
				continue;
			}
			$upcoming[] = $l->t('%1$s with %2$s on %3$s', [
				$interview->getTitle(),
				$name,
				(string)$l->l('datetime', $interview->getStartAt()),
			]);
		}
		if ($upcoming !== []) {
			$sections[] = [
				$l->n('%n interview in the next 7 days', '%n interviews in the next 7 days', count($upcoming)),
				array_slice($upcoming, 0, 10),
			];
		}

		// Offers awaiting my approval
		$pendingOffers = $this->offerMapper->findPendingForApprover($uid);
		$offerNames = $this->namesFor(array_map(static fn ($o) => $o->getCandidateId(), $pendingOffers));
		$approvals = [];
		foreach ($pendingOffers as $offer) {
			$name = $offerNames[$offer->getCandidateId()] ?? null;
			if ($name !== null) {
				$approvals[] = $l->t('Offer for %s', [$name]);
			}
		}
		if ($approvals !== []) {
			$sections[] = [
				$l->n('%n offer awaiting your approval', '%n offers awaiting your approval', count($approvals)),
				$approvals,
			];
		}

		return $sections;
	}

	/**
	 * @param int[] $candidateIds
	 * @return array<int, string> candidateId => display name
	 */
	private function namesFor(array $candidateIds): array {
		$names = [];
		foreach ($this->candidateMapper->findByIds(array_values(array_unique($candidateIds))) as $candidate) {
			$names[$candidate->getId()] = $candidate->getDisplayName();
		}
		return $names;
	}
}
