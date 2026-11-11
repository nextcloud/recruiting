<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Security\ISecureRandom;

/**
 * The candidate self-service page: one secret link per candidate where they
 * see a coarse status, their upcoming interviews, and can withdraw, ask for
 * deletion or add documents — no account needed.
 */
class PortalService {
	public const TOKEN_LENGTH = 48;
	/** Upload cap per candidate, counting every document on file */
	public const MAX_DOCUMENTS = 10;

	public function __construct(
		private CandidateMapper $candidateMapper,
		private InterviewMapper $interviewMapper,
		private OpeningMapper $openingMapper,
		private DocumentMapper $documentMapper,
		private DocumentService $documents,
		private StageService $stages,
		private TimelineService $timeline,
		private NotificationService $notifications,
		private TeamMemberMapper $teamMapper,
		private ConfigService $config,
		private CandidateTimeFormatter $timeFormatter,
		private IGroupManager $groupManager,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	public function newToken(): string {
		return $this->random->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
	}

	/**
	 * The candidate's portal link. Candidates created before the portal
	 * existed get their token here, on first use.
	 */
	public function portalUrl(Candidate $candidate): string {
		if ($candidate->getAnonymizedAt() !== null) {
			return '';
		}
		$token = $candidate->getPortalToken();
		if ($token === null || $token === '') {
			$token = $this->newToken();
			$candidate->setPortalToken($token);
			$this->candidateMapper->update($candidate);
		}
		return $this->urlGenerator->linkToRouteAbsolute('recruiting.portal.show', ['token' => $token]);
	}

	/**
	 * Resolve a portal token; anonymized candidates and malformed tokens
	 * resolve to nothing, exactly like unknown ones.
	 */
	public function findByToken(string $token): ?Candidate {
		if (strlen($token) < 32 || !ctype_alnum($token)) {
			return null;
		}
		$candidate = $this->candidateMapper->findByPortalToken($token);
		if ($candidate === null || $candidate->getAnonymizedAt() !== null) {
			return null;
		}
		return $candidate;
	}

	/**
	 * Everything the page shows — and nothing internal: no stage names,
	 * votes, comments, interviewer names or rejection reasons.
	 *
	 * @return array{name: string, openingTitle: ?string, status: string, statusLabel: string, statusText: string, interviews: list<array{title: string, confirmed: bool, date: string, time: string, url: string}>, canWithdraw: bool, canUpload: bool, documentCount: int, maxDocuments: int, deletionRequestedAt: ?\DateTime}
	 */
	public function view(Candidate $candidate): array {
		$status = CandidateStatus::of($candidate);
		$documentCount = $this->documentCount($candidate);
		return [
			'name' => $candidate->getDisplayName(),
			'openingTitle' => $this->openingMapper->findTitle($candidate->getOpeningId()),
			'status' => $status,
			'statusLabel' => CandidateStatus::label($status, $this->l10n),
			'statusText' => CandidateStatus::explanation($status, $this->l10n),
			'interviews' => $candidate->isTerminal() ? [] : $this->upcomingInterviews($candidate),
			'canWithdraw' => !$candidate->isTerminal(),
			'canUpload' => !$candidate->isTerminal() && $documentCount < self::MAX_DOCUMENTS,
			'documentCount' => $documentCount,
			'maxDocuments' => self::MAX_DOCUMENTS,
			'deletionRequestedAt' => $candidate->getDeletionRequestedAt(),
		];
	}

	/**
	 * The candidate withdraws. The hiring team is told; open interviews are
	 * cancelled through CandidateWithdrawnEvent, which notifies the
	 * interviewers.
	 *
	 * @throws ValidationException
	 */
	public function withdraw(Candidate $candidate): void {
		if ($candidate->isTerminal()) {
			throw new ValidationException($this->l10n->t('This application is already closed.'));
		}
		$this->stages->apply($candidate, Candidate::STAGE_WITHDRAWN, null, ['byCandidate' => true]);
		$this->notifications->notify($this->teamUids($candidate), NotificationService::SUBJECT_CANDIDATE_WITHDREW, $candidate, null, [
			'openingTitle' => $this->openingMapper->findTitle($candidate->getOpeningId()) ?? '',
		]);
	}

	/**
	 * A GDPR Art. 17 request: recorded and routed to the HR group, who carry
	 * out the deletion. Asking twice changes nothing.
	 */
	public function requestDeletion(Candidate $candidate): void {
		if ($candidate->getDeletionRequestedAt() !== null) {
			return;
		}
		$candidate->setDeletionRequestedAt($this->timeFactory->getDateTime());
		$this->candidateMapper->update($candidate);
		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_DELETION_REQUESTED, null);
		$this->notifications->notify($this->hrUids(), NotificationService::SUBJECT_DELETION_REQUESTED, $candidate, null);
	}

	/**
	 * Same limits and type allow-list as every other upload, plus a cap on
	 * the number of documents per candidate.
	 *
	 * @throws ValidationException
	 */
	public function addDocument(Candidate $candidate, string $name, string $mime, string $content): void {
		if ($candidate->isTerminal()) {
			throw new ValidationException($this->l10n->t('This application is already closed.'));
		}
		if ($this->documentCount($candidate) >= self::MAX_DOCUMENTS) {
			throw new ValidationException($this->l10n->t('You can upload at most %s documents.', [(string)self::MAX_DOCUMENTS]));
		}
		$document = $this->documents->add($candidate, $name, $mime, $content, null);
		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_DOCUMENT_ADDED, null, [
			'name' => $document->getName(),
			'byCandidate' => true,
		]);
		$this->notifications->notify($this->teamUids($candidate), NotificationService::SUBJECT_CANDIDATE_UPLOADED, $candidate, null);
	}

	private function documentCount(Candidate $candidate): int {
		return $this->documentMapper->countFor([$candidate->getId()])[$candidate->getId()] ?? 0;
	}

	/**
	 * @return list<array{title: string, confirmed: bool, date: string, time: string, url: string}>
	 */
	private function upcomingInterviews(Candidate $candidate): array {
		$now = $this->timeFactory->getDateTime();
		$result = [];
		foreach ($this->interviewMapper->findForCandidate($candidate->getId()) as $interview) {
			$confirmed = $interview->getStatus() === Interview::STATUS_CONFIRMED;
			if (!$confirmed && $interview->getStatus() !== Interview::STATUS_PROPOSED) {
				continue;
			}
			$startAt = $interview->getStartAt();
			if ($confirmed && ($interview->getEndAt() === null || $interview->getEndAt() <= $now)) {
				continue;
			}
			$when = $confirmed && $startAt !== null
				? $this->timeFormatter->format($startAt, $interview->getCreatedBy())
				: ['date' => '', 'time' => ''];
			$result[] = [$startAt?->getTimestamp() ?? PHP_INT_MAX, [
				'title' => $interview->getTitle(),
				'confirmed' => $confirmed,
				'date' => $when['date'],
				'time' => $when['time'],
				'url' => $this->urlGenerator->linkToRouteAbsolute('recruiting.public.showInterview', ['token' => $interview->getPublicToken()]),
			]];
		}
		usort($result, static fn (array $a, array $b) => $a[0] <=> $b[0]);
		return array_map(static fn (array $pair): array => $pair[1], $result);
	}

	/**
	 * Who hears about the candidate's own actions: the opening's hiring
	 * managers, or the HR group when there are none (triage, or an opening
	 * run by recruiters alone).
	 *
	 * @return string[]
	 */
	private function teamUids(Candidate $candidate): array {
		$managers = [];
		if ($candidate->getOpeningId() !== null) {
			foreach ($this->teamMapper->findForOpening($candidate->getOpeningId()) as $member) {
				if ($member->getRole() === TeamMember::ROLE_MANAGER) {
					$managers[] = $member->getUid();
				}
			}
		}
		return $managers !== [] ? $managers : $this->hrUids();
	}

	/**
	 * @return string[]
	 */
	private function hrUids(): array {
		$group = $this->groupManager->get($this->config->getHrGroup());
		if ($group === null) {
			return [];
		}
		return array_map(static fn (IUser $user) => $user->getUID(), $group->getUsers());
	}
}
