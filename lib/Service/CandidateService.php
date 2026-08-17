<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Assignment;
use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Db\Vote;
use OCA\Recruiting\Db\VoteMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\ICommentsManager;
use OCP\IL10N;
use OCP\IUserManager;

class CandidateService {
	private const DUPLICATE_WINDOW_DAYS = 90;

	/**
	 * Placeholder for applications without a usable sender name. Kept
	 * untranslated on purpose: AiService recognizes it to know the name is
	 * still missing, and a translated marker would break that once the
	 * server language changes.
	 */
	public const UNKNOWN_APPLICANT = 'Unknown applicant';

	public function __construct(
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private TeamMemberMapper $teamMapper,
		private AssignmentMapper $assignmentMapper,
		private DocumentMapper $documentMapper,
		private VoteMapper $voteMapper,
		private InterviewMapper $interviewMapper,
		private InterviewAttendeeMapper $attendeeMapper,
		private OfferMapper $offerMapper,
		private OfferService $offers,
		private PermissionService $permissions,
		private DocumentService $documents,
		private InterviewService $interviews,
		private MailService $mail,
		private PoolService $pool,
		private StageService $stages,
		private TimelineService $timeline,
		private TimelineEventMapper $eventMapper,
		private NotificationService $notifications,
		private ActivityPublisher $activity,
		private ICommentsManager $commentsManager,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Candidate {
		return $this->candidateMapper->find($id);
	}

	/** Cards per stage in one board/table response; more via ?stage=&offset= */
	public const PAGE_SIZE = 100;

	/**
	 * Board/table data for one opening, scoped by role: interviewers only
	 * get the candidates assigned to them.
	 *
	 * Paged per stage (spec §10.6): the first response carries up to
	 * PAGE_SIZE cards per stage; a follow-up call with $stage/$offset pages
	 * within one stage. Interviewers get their full (small, assignment-
	 * bounded) list in one response instead.
	 *
	 * @return array{cards: array, page: int}
	 * @throws NotPermittedException
	 */
	public function listForOpening(string $uid, int $openingId, ?string $stage = null, int $offset = 0): array {
		$this->permissions->assertCanViewOpening($uid, $openingId);

		$role = $this->permissions->roleForOpening($uid, $openingId);
		if ($role === TeamMember::ROLE_INTERVIEWER) {
			$visible = array_merge(
				$this->assignmentMapper->findCandidateIdsForUser($uid),
				$this->attendeeMapper->findCandidateIdsForUser($uid),
			);
			$candidates = array_values(array_filter(
				$this->candidateMapper->findByIds($visible),
				static fn (Candidate $c) => $c->getOpeningId() === $openingId,
			));
			usort($candidates, static fn (Candidate $a, Candidate $b) => $a->getCreatedAt() <=> $b->getCreatedAt() ?: $a->getId() <=> $b->getId());
			return ['cards' => $this->serializeCards($candidates, $uid), 'page' => self::PAGE_SIZE];
		}

		if ($stage !== null) {
			$candidates = $this->candidateMapper->findForOpening($openingId, $stage, self::PAGE_SIZE, max(0, $offset));
		} else {
			$candidates = [];
			foreach (array_merge(Candidate::ACTIVE_STAGES, Candidate::TERMINAL_STAGES) as $boardStage) {
				$candidates = array_merge(
					$candidates,
					$this->candidateMapper->findForOpening($openingId, $boardStage, self::PAGE_SIZE, 0),
				);
			}
		}

		return ['cards' => $this->serializeCards($candidates, $uid), 'page' => self::PAGE_SIZE];
	}

	/**
	 * Triage inbox: candidates that arrived by mail without a matching opening.
	 *
	 * @throws NotPermittedException
	 */
	public function triage(string $uid): array {
		$this->permissions->assertRecruiter($uid);
		return $this->serializeCards($this->candidateMapper->findTriage(), $uid);
	}

	/**
	 * Candidates the user still has to review (assigned, no vote yet, active).
	 */
	public function myReviews(string $uid): array {
		$ids = $this->assignmentMapper->findCandidateIdsForUser($uid);
		$candidates = array_values(array_filter(
			$this->candidateMapper->findByIds($ids),
			// An assignment outlives being dropped from the hiring team, so
			// visibility is re-checked here — the list must never show more
			// than the detail view would hand out.
			fn (Candidate $c) => !$c->isTerminal() && $this->permissions->canViewCandidate($uid, $c),
		));
		$myVotes = [];
		foreach ($this->voteMapper->findForUser($uid, array_map(static fn (Candidate $c) => $c->getId(), $candidates)) as $vote) {
			$myVotes[$vote->getCandidateId()] = $vote->jsonSerialize();
		}

		$result = [];
		foreach ($this->serializeCards($candidates, $uid) as $card) {
			$card['myVote'] = $myVotes[$card['id']] ?? null;
			$result[] = $card;
		}
		usort($result, static fn ($a, $b) => ($a['myVote'] === null ? 0 : 1) <=> ($b['myVote'] === null ? 0 : 1));
		return $result;
	}

	/**
	 * Upcoming interviews of the user, with candidate context.
	 */
	public function myInterviews(string $uid): array {
		$now = $this->timeFactory->getDateTime();
		$result = [];
		foreach ($this->interviewMapper->findUpcomingForUser($uid, $now) as $interview) {
			try {
				$candidate = $this->candidateMapper->find($interview->getCandidateId());
			} catch (DoesNotExistException) {
				continue;
			}
			$entry = $interview->jsonSerialize();
			$entry['candidate'] = [
				'id' => $candidate->getId(),
				'displayName' => $candidate->getDisplayName(),
				'openingId' => $candidate->getOpeningId(),
				'openingTitle' => $this->openingMapper->findTitle($candidate->getOpeningId()),
			];
			$entry['attendees'] = $this->interviews->attendeesFor($interview);
			$result[] = $entry;
		}
		return $result;
	}

	/**
	 * Full detail for the sidebar.
	 *
	 * @throws NotPermittedException
	 */
	public function get(string $uid, int $id): array {
		$candidate = $this->candidateMapper->find($id);
		$this->permissions->assertCanViewCandidate($uid, $candidate);

		$canManage = $this->permissions->canManageCandidate($uid, $candidate);
		$data = $this->serializeCards([$candidate], $uid)[0];

		$canSeeOffers = $this->permissions->canSeeOffers($uid, $candidate);
		$data['openingTitle'] = $this->openingMapper->findTitle($candidate->getOpeningId());
		$data['permissions'] = [
			'manage' => $canManage,
			'vote' => $this->permissions->canVote($uid, $candidate),
			'comment' => $this->permissions->canComment($uid, $candidate),
			'offers' => $canSeeOffers,
		];
		// Offer data (salary!) never reaches interviewers or observers
		$data['offers'] = $canSeeOffers ? $this->offers->listFor($candidate) : [];
		$data['documents'] = array_map(
			static fn ($d) => $d->jsonSerialize(),
			$this->documents->listFor($id),
		);
		$data['screeners'] = array_map(
			fn (Assignment $a) => array_merge($a->jsonSerialize(), [
				'displayName' => $this->displayName($a->getUid()),
			]),
			$this->assignmentMapper->findForCandidate($id),
		);
		$data['votes'] = $this->serializeVotes($uid, $candidate);
		$data['interviews'] = array_map(
			fn (Interview $i) => $this->interviews->serialize($i),
			$this->interviewMapper->findForCandidate($id),
		);
		$data['timeline'] = $this->serializeTimeline($this->timeline->eventsFor($id), $canSeeOffers);
		return $data;
	}

	/**
	 * Manual entry by a recruiter or hiring manager (spec §4.2).
	 *
	 * @throws ValidationException|NotPermittedException
	 */
	public function createManual(string $uid, array $data): array {
		$openingId = isset($data['openingId']) && $data['openingId'] !== null && $data['openingId'] !== ''
			? (int)$data['openingId'] : null;
		if ($openingId !== null) {
			$this->permissions->assertCanManageOpening($uid, $openingId);
			$this->openingMapper->find($openingId); // must exist
		} else {
			$this->permissions->assertRecruiter($uid);
		}

		$name = trim((string)($data['displayName'] ?? ''));
		if ($name === '') {
			throw new ValidationException($this->l10n->t('A name is required'));
		}
		$email = trim((string)($data['email'] ?? ''));
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new ValidationException($this->l10n->t('The email address is invalid'));
		}

		$candidate = $this->insertCandidate(
			$openingId,
			$name,
			$email,
			trim((string)($data['phone'] ?? '')),
			Candidate::SOURCE_MANUAL,
			$uid,
		);
		return $this->get($uid, $candidate->getId());
	}

	/**
	 * Shared insertion path for manual entry and mail ingestion: handles
	 * duplicate detection, the timeline, activity and manager notifications.
	 */
	public function insertCandidate(?int $openingId, string $name, string $email, string $phone, string $source, ?string $createdBy): Candidate {
		$now = $this->timeFactory->getDateTime();

		// Mail headers are attacker/typo territory: clamp to the column sizes,
		// or one oversized From name would abort the insert and — because
		// ingestion marks poison messages as seen — silently lose the
		// application forever.
		$name = mb_substr(trim($name), 0, 250);
		$email = mb_substr(trim($email), 0, 250);
		$phone = mb_substr(trim($phone), 0, 60);

		$candidate = new Candidate();
		$candidate->setOpeningId($openingId);
		$candidate->setDisplayName($name !== '' ? $name : ($email !== '' ? $email : self::UNKNOWN_APPLICANT));
		$candidate->setEmail($email);
		$candidate->setPhone($phone);
		$candidate->setSource($source);
		$candidate->setStage(Candidate::STAGE_NEW);
		$candidate->setStageChangedAt($now);
		$candidate->setCreatedBy($createdBy);
		$candidate->setCreatedAt($now);

		$duplicate = $this->candidateMapper->findRecentByEmail(
			$email,
			$openingId,
			(clone $now)->modify('-' . self::DUPLICATE_WINDOW_DAYS . ' days'),
		);
		if ($duplicate !== null && $openingId !== null) {
			$candidate->setDuplicateOf($duplicate->getId());
		}

		$candidate = $this->candidateMapper->insert($candidate);
		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_CREATED, $createdBy, ['source' => $source]);

		if ($openingId !== null) {
			$this->activity->publish($candidate, 'application_created', $createdBy);
			$this->notifyManagers($candidate, $createdBy);
		}
		return $candidate;
	}

	/**
	 * @throws NotPermittedException|ValidationException
	 */
	public function update(string $uid, int $id, array $data): array {
		$candidate = $this->candidateMapper->find($id);
		$this->permissions->assertCanManageCandidate($uid, $candidate);

		if (array_key_exists('displayName', $data)) {
			$name = trim((string)$data['displayName']);
			if ($name === '') {
				throw new ValidationException($this->l10n->t('A name is required'));
			}
			$candidate->setDisplayName($name);
		}
		if (array_key_exists('email', $data)) {
			$email = trim((string)$data['email']);
			if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new ValidationException($this->l10n->t('The email address is invalid'));
			}
			$candidate->setEmail($email);
		}
		if (array_key_exists('phone', $data)) {
			$candidate->setPhone(trim((string)$data['phone']));
		}
		$this->candidateMapper->update($candidate);
		return $this->get($uid, $id);
	}

	/**
	 * Move a candidate on the board. Rejection has its own endpoint since
	 * it carries a reason and usually a mail.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function setStage(string $uid, int $id, string $stage): array {
		$candidate = $this->candidateMapper->find($id);
		$this->permissions->assertCanManageCandidate($uid, $candidate);

		$allowed = array_merge(Candidate::ACTIVE_STAGES, [Candidate::STAGE_HIRED, Candidate::STAGE_WITHDRAWN]);
		if (!in_array($stage, $allowed, true)) {
			throw new ValidationException($this->l10n->t('Invalid stage'));
		}
		if ($candidate->getOpeningId() === null) {
			throw new ValidationException($this->l10n->t('Assign the candidate to an opening first'));
		}
		// A manual move is the one place where reopening a finished candidate
		// is intended (e.g. a withdrawal that turned out to be a mistake).
		$this->stages->apply($candidate, $stage, $uid, allowFromTerminal: true);
		return $this->get($uid, $id);
	}

	/**
	 * Move a triaged candidate into an opening.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function assignToOpening(string $uid, int $id, int $openingId): array {
		$this->permissions->assertRecruiter($uid);
		$candidate = $this->candidateMapper->find($id);
		if ($candidate->getOpeningId() !== null) {
			throw new ValidationException($this->l10n->t('This candidate already belongs to an opening'));
		}
		$opening = $this->openingMapper->find($openingId);

		$candidate->setOpeningId($openingId);
		$now = $this->timeFactory->getDateTime();
		$duplicate = $this->candidateMapper->findRecentByEmail(
			$candidate->getEmail(),
			$openingId,
			(clone $now)->modify('-' . self::DUPLICATE_WINDOW_DAYS . ' days'),
		);
		if ($duplicate !== null && $duplicate->getId() !== $candidate->getId()) {
			$candidate->setDuplicateOf($duplicate->getId());
		}
		$this->candidateMapper->update($candidate);

		$this->timeline->record($id, TimelineEvent::TYPE_OPENING_ASSIGNED, $uid, ['openingTitle' => $opening->getTitle()]);
		$this->activity->publish($candidate, 'application_created', $uid);
		$this->notifyManagers($candidate, $uid);
		return $this->get($uid, $id);
	}

	/**
	 * Reject with a reason; optionally sends the (already previewed) mail
	 * first so the candidate never ends up rejected-but-uninformed silently
	 * failing (spec §4.5). With $askPool the mail carries a talent-pool
	 * consent link (spec §4.8).
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function reject(string $uid, int $id, string $reason, ?string $mailSubject, ?string $mailBody, bool $askPool = false): array {
		$candidate = $this->candidateMapper->find($id);
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		if (!in_array($reason, Candidate::REJECTION_REASONS, true)) {
			throw new ValidationException($this->l10n->t('Invalid rejection reason'));
		}
		if ($candidate->getStage() === Candidate::STAGE_REJECTED) {
			throw new ValidationException($this->l10n->t('This candidate is already rejected'));
		}

		if ($mailSubject !== null && $mailBody !== null) {
			if ($askPool) {
				$url = $this->pool->prepareConsentUrl($candidate, $uid);
				$mailBody = rtrim($mailBody) . "\n\n"
					. $this->l10n->t('PS: We would love to consider you for future openings. If you agree that we keep your application on file, just confirm here: %s', [$url]);
			}
			$this->mail->sendToCandidate($candidate, $mailSubject, $mailBody, $uid);
		}

		$candidate->setRejectionReason($reason);
		$this->stages->apply($candidate, Candidate::STAGE_REJECTED, $uid, ['reason' => $reason], allowFromTerminal: true);
		$this->activity->publish($candidate, 'candidate_rejected', $uid);
		return $this->get($uid, $id);
	}

	/**
	 * Copy a talent-pool member into a new opening (spec §4.8): a fresh
	 * candidate in "New" with source "pool", documents included.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function addPoolCandidateToOpening(string $uid, int $poolCandidateId, int $openingId): array {
		$this->permissions->assertRecruiter($uid);
		$source = $this->candidateMapper->find($poolCandidateId);
		if (!$source->getPoolMember() || $source->getAnonymizedAt() !== null) {
			throw new ValidationException($this->l10n->t('This candidate is not in the talent pool'));
		}
		$opening = $this->openingMapper->find($openingId);

		$copy = $this->insertCandidate(
			$openingId,
			$source->getDisplayName(),
			$source->getEmail(),
			$source->getPhone(),
			Candidate::SOURCE_POOL,
			$uid,
		);
		$copy->setAiSummary($source->getAiSummary());
		$this->candidateMapper->update($copy);
		foreach ($this->documents->listFor($poolCandidateId) as $document) {
			try {
				$file = $this->documents->getFile($document);
				$this->documents->add($copy, $document->getName(), $document->getMime(), $file->getContent(), $uid);
			} catch (\Exception) {
				// missing file — skip
			}
		}
		$this->timeline->record($poolCandidateId, TimelineEvent::TYPE_POOL_COPIED, $uid, ['openingTitle' => $opening->getTitle()]);
		return $this->get($uid, $copy->getId());
	}

	/**
	 * Hard delete: candidate, documents, votes, comments, interviews,
	 * notifications (GDPR delete-on-request, spec §4.8).
	 *
	 * @throws NotPermittedException
	 */
	public function destroy(string $uid, int $id): void {
		$this->permissions->assertRecruiter($uid);
		$candidate = $this->candidateMapper->find($id);

		$this->interviews->cancelAllFor($candidate, $uid, notify: false);
		$this->documents->deleteAllFor($id);
		$this->voteMapper->deleteForCandidate($id);
		$this->assignmentMapper->deleteForCandidate($id);
		// Slots and attendees hang off the interviews, so they must go first
		$this->interviews->deleteAllFor($id);
		$this->offerMapper->deleteForCandidate($id);
		$this->notifications->purgeForCandidate($id);
		try {
			$this->commentsManager->deleteCommentsAtObject(CommentService::OBJECT_TYPE, (string)$id);
		} catch (\Exception) {
			// comments are best-effort
		}
		$this->eventMapper->deleteForCandidate($id);
		$this->candidateMapper->delete($candidate);
	}

	private function notifyManagers(Candidate $candidate, ?string $actorUid): void {
		$openingId = $candidate->getOpeningId();
		if ($openingId === null) {
			return;
		}
		$managers = [];
		foreach ($this->teamMapper->findForOpening($openingId) as $member) {
			if ($member->getRole() === TeamMember::ROLE_MANAGER) {
				$managers[] = $member->getUid();
			}
		}
		$this->notifications->notify($managers, NotificationService::SUBJECT_NEW_APPLICATION, $candidate, $actorUid, [
			'openingTitle' => $this->openingMapper->findTitle($openingId) ?? '',
		]);
	}

	/**
	 * Compact representation for board cards and lists.
	 *
	 * Everything a card needs is fetched in a handful of batched queries for
	 * the whole list — rendering a board must not cost four queries per
	 * candidate.
	 *
	 * @param Candidate[] $candidates
	 */
	private function serializeCards(array $candidates, string $uid): array {
		if ($candidates === []) {
			return [];
		}
		$ids = array_map(static fn (Candidate $c) => $c->getId(), $candidates);

		$now = $this->timeFactory->getDateTime();
		$tallies = $this->voteMapper->tallyFor($ids);
		$ownVotes = [];
		foreach ($this->voteMapper->findForUser($uid, $ids) as $vote) {
			$ownVotes[$vote->getCandidateId()] = true;
		}
		$screenerCounts = $this->assignmentMapper->countFor($ids);
		$screenerUids = $this->assignmentMapper->uidsFor($ids);
		$votedUids = $this->voteMapper->votedUidsFor($ids);
		$documentCounts = $this->documentMapper->countFor($ids);
		$nextInterviews = $this->interviewMapper->nextConfirmedFor($ids, $now);
		$activeOffers = $this->offerMapper->activeFor($ids);

		return array_map(function (Candidate $candidate) use ($uid, $now, $tallies, $ownVotes, $screenerCounts, $screenerUids, $votedUids, $documentCounts, $nextInterviews, $activeOffers) {
			$id = $candidate->getId();
			$tally = array_merge(
				[Vote::YES => 0, Vote::MAYBE => 0, Vote::NO => 0],
				$tallies[$id] ?? [],
			);

			$data = $candidate->jsonSerialize();
			$data['voteCount'] = array_sum($tally);
			$data['voteTally'] = $this->maySeeVotes($uid, $candidate, isset($ownVotes[$id])) ? $tally : null;
			$data['screenerCount'] = $screenerCounts[$id] ?? 0;
			$data['documentCount'] = $documentCounts[$id] ?? 0;
			$data['nextInterviewAt'] = $nextInterviews[$id] ?? null;
			// Who is on it: assigned screeners, with a voted flag. Only the
			// fact that somebody voted — the value stays behind the
			// anti-anchoring rule (spec §4.3).
			$voted = $votedUids[$id] ?? [];
			$data['screeners'] = array_map(fn (string $screenerUid) => [
				'uid' => $screenerUid,
				'displayName' => $this->displayName($screenerUid),
				'voted' => in_array($screenerUid, $voted, true),
			], $screenerUids[$id] ?? []);
			$data['attention'] = $this->attentionFor($candidate, $data, $activeOffers[$id] ?? null, $now);
			return $data;
		}, $candidates);
	}

	/**
	 * What is stuck on this candidate (spec §10.6): the board surfaces these
	 * as a "needs attention" lens. Server-side so board, table and future
	 * digests agree on what "stuck" means.
	 *
	 * @param array<string, mixed> $data the serialized card so far
	 * @param ?array{status: string, validUntil: ?string} $activeOffer
	 * @return string[]
	 */
	private function attentionFor(Candidate $candidate, array $data, ?array $activeOffer, \DateTimeImmutable|\DateTime $now): array {
		if ($candidate->isTerminal() || $candidate->getOpeningId() === null) {
			return [];
		}
		$since = $candidate->getStageChangedAt() ?? $candidate->getCreatedAt();
		$days = $since !== null ? (int)$now->diff($since)->format('%a') : 0;

		$reasons = [];
		if ($candidate->getStage() === Candidate::STAGE_NEW && $days >= 7) {
			$reasons[] = 'waiting';
		}
		if ($candidate->getStage() === Candidate::STAGE_SCREENING && $data['voteCount'] === 0 && $days >= 5) {
			$reasons[] = 'no_votes';
		}
		if ($candidate->getStage() === Candidate::STAGE_INTERVIEW && $data['nextInterviewAt'] === null && $days >= 3) {
			$reasons[] = 'no_interview';
		}
		if ($candidate->getStage() === Candidate::STAGE_OFFER) {
			if ($activeOffer === null && $days >= 3) {
				$reasons[] = 'no_offer';
			} elseif ($activeOffer !== null && $activeOffer['status'] === 'sent' && $activeOffer['validUntil'] !== null) {
				try {
					$validUntil = new \DateTime($activeOffer['validUntil']);
					if ($validUntil <= (clone $now)->modify('+3 days')) {
						$reasons[] = 'offer_expiring';
					}
				} catch (\Exception) {
					// unparsable date — no hint rather than a broken board
				}
			}
		}
		if ($days >= 30) {
			$reasons[] = 'stale';
		}
		return $reasons;
	}

	/**
	 * Anti-anchoring rule (spec §4.3): interviewers see the votes of others
	 * only once they have submitted their own. Managers, recruiters and
	 * observers always see everything.
	 */
	private function maySeeVotes(string $uid, Candidate $candidate, bool $hasOwnVote): bool {
		if ($hasOwnVote || $this->permissions->canManageCandidate($uid, $candidate)) {
			return true;
		}
		$openingId = $candidate->getOpeningId();
		return $openingId !== null
			&& $this->permissions->roleForOpening($uid, $openingId) === TeamMember::ROLE_OBSERVER;
	}

	private function serializeVotes(string $uid, Candidate $candidate): array {
		$votes = $this->voteMapper->findForCandidate($candidate->getId());
		$hasOwnVote = false;
		foreach ($votes as $vote) {
			if ($vote->getUid() === $uid) {
				$hasOwnVote = true;
				break;
			}
		}
		$canSee = $this->maySeeVotes($uid, $candidate, $hasOwnVote);
		return array_map(function (Vote $vote) use ($uid, $canSee) {
			$data = $vote->jsonSerialize();
			$data['displayName'] = $this->displayName($vote->getUid());
			if (!$canSee && $vote->getUid() !== $uid) {
				// Who voted is visible, what they voted is not (yet)
				$data['vote'] = null;
				$data['comment'] = null;
			}
			return $data;
		}, $votes);
	}

	/**
	 * The timeline follows the same confidentiality rules as the rest of the
	 * app: outgoing mail bodies (an offer mail states the salary!) and offer
	 * events are management information, so they are stripped for anyone who
	 * may not see offer data — otherwise the timeline would be a backdoor
	 * around canSeeOffers.
	 *
	 * @param TimelineEvent[] $events
	 */
	private function serializeTimeline(array $events, bool $canSeeOffers): array {
		$result = [];
		foreach ($events as $event) {
			$type = $event->getType();
			if (!$canSeeOffers && str_starts_with($type, 'offer_')) {
				continue;
			}
			$data = $event->jsonSerialize();
			if (!$canSeeOffers && $type === TimelineEvent::TYPE_MAIL_SENT) {
				// Keep the fact, drop subject, body and recipient
				$data['data'] = ['redacted' => true];
			}
			$data['actorDisplayName'] = $event->getActorUid() !== null ? $this->displayName($event->getActorUid()) : null;
			$result[] = $data;
		}
		return $result;
	}

	private function displayName(string $uid): string {
		return $this->userManager->getDisplayName($uid) ?? $uid;
	}
}
