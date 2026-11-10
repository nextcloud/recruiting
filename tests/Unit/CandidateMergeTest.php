<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Db\VoteMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\ActivityPurgeService;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\OfferService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\PoolService;
use OCA\Recruiting\Service\PortalService;
use OCA\Recruiting\Service\StageService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CandidateMergeTest extends TestCase {
	private CandidateMapper&MockObject $candidateMapper;
	private OpeningMapper&MockObject $openingMapper;
	private AssignmentMapper&MockObject $assignmentMapper;
	private VoteMapper&MockObject $voteMapper;
	private InterviewMapper&MockObject $interviewMapper;
	private OfferMapper&MockObject $offerMapper;
	private PermissionService&MockObject $permissions;
	private DocumentService&MockObject $documents;
	private TimelineService&MockObject $timeline;
	private TimelineEventMapper&MockObject $eventMapper;
	private NotificationService&MockObject $notifications;
	private ICommentsManager&MockObject $commentsManager;
	private CandidateService $service;

	/** @var array<int, Candidate> */
	private array $rows = [];

	protected function setUp(): void {
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->candidateMapper->method('find')->willReturnCallback(fn (int $id) => $this->rows[$id]);
		$this->openingMapper = $this->createMock(OpeningMapper::class);
		$this->assignmentMapper = $this->createMock(AssignmentMapper::class);
		$this->voteMapper = $this->createMock(VoteMapper::class);
		$this->interviewMapper = $this->createMock(InterviewMapper::class);
		$this->offerMapper = $this->createMock(OfferMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->eventMapper = $this->createMock(TimelineEventMapper::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->commentsManager = $this->createMock(ICommentsManager::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-08-16T12:00:00+00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->service = new CandidateService(
			$this->candidateMapper,
			$this->openingMapper,
			$this->createMock(TeamMemberMapper::class),
			$this->assignmentMapper,
			$this->createMock(DocumentMapper::class),
			$this->voteMapper,
			$this->interviewMapper,
			$this->createMock(InterviewAttendeeMapper::class),
			$this->offerMapper,
			$this->createMock(OfferService::class),
			$this->permissions,
			$this->documents,
			$this->createMock(InterviewService::class),
			$this->createMock(MailService::class),
			$this->createMock(PoolService::class),
			$this->createMock(StageService::class),
			$this->timeline,
			$this->eventMapper,
			$this->notifications,
			$this->createMock(ActivityPublisher::class),
			$this->createMock(ActivityPurgeService::class),
			$this->commentsManager,
			$this->createMock(IUserManager::class),
			$timeFactory,
			$l10n,
			$this->createMock(PortalService::class),
		);
	}

	private function candidate(int $id, ?int $openingId, string $email = 'jane@example.com'): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setOpeningId($openingId);
		$candidate->setEmail($email);
		$candidate->setDisplayName('Jane');
		$candidate->setCreatedAt(new \DateTime('2026-08-01T00:00:00+00:00'));
		$candidate->resetUpdatedFields();
		$this->rows[$id] = $candidate;
		return $candidate;
	}

	private function expectNothingMoved(): void {
		$this->documents->expects($this->never())->method('moveAll');
		$this->voteMapper->expects($this->never())->method('moveToCandidate');
		$this->candidateMapper->expects($this->never())->method('delete');
	}

	public function testMergeMovesEverythingAndDeletesTheDuplicate(): void {
		$target = $this->candidate(1, 5);
		$target->setDuplicateOf(2);
		$target->resetUpdatedFields();
		$source = $this->candidate(2, 5, 'JANE@example.com');
		$source->setReplyToken('reply-token');
		$this->permissions->method('canViewCandidate')->willReturn(true);

		$this->documents->expects($this->once())->method('moveAll')->with(2, $target)->willReturn(2);

		$event = new TimelineEvent();
		$event->setCandidateId(2);
		$event->setType(TimelineEvent::TYPE_STAGE_CHANGED);
		$event->setData('{"from":"new","to":"screening"}');
		$this->eventMapper->method('findForCandidate')->with(2)->willReturn([$event]);
		$this->eventMapper->expects($this->once())->method('update')->with($event);

		$comment = $this->createMock(IComment::class);
		$comment->expects($this->once())->method('setObject')->with('recruiting_candidate', '1');
		$this->commentsManager->method('getForObject')->with('recruiting_candidate', '2')->willReturn([$comment]);
		$this->commentsManager->expects($this->once())->method('save')->with($comment);

		$this->assignmentMapper->expects($this->once())->method('moveToCandidate')->with(2, 1);
		$this->voteMapper->expects($this->once())->method('moveToCandidate')->with(2, 1);
		$this->interviewMapper->expects($this->once())->method('moveToCandidate')->with(2, 1);
		$this->offerMapper->expects($this->once())->method('moveToCandidate')->with(2, 1);
		$this->candidateMapper->expects($this->once())->method('repointDuplicates')->with(2, 1);
		$this->candidateMapper->expects($this->once())->method('update')->with($target);
		$this->timeline->expects($this->once())->method('record')
			->with(1, TimelineEvent::TYPE_MERGED, 'hr', ['mergedId' => 2, 'documents' => 2]);
		$this->notifications->expects($this->once())->method('purgeForCandidate')->with(2);
		$this->candidateMapper->expects($this->once())->method('delete')->with($source);

		$detail = $this->service->merge('hr', 2, 1);

		$this->assertSame(1, $detail['id']);
		$this->assertSame(1, $event->getCandidateId());
		$this->assertSame(['from' => 'new', 'to' => 'screening', 'mergedFrom' => 2], $event->getDecodedData());
		$this->assertNull($target->getDuplicateOf());
		$this->assertSame('reply-token', $target->getReplyToken());
	}

	public function testDifferentOpeningsAreSeparateApplications(): void {
		$this->candidate(1, 5);
		$this->candidate(2, 6);
		$this->expectNothingMoved();
		$this->expectException(ValidationException::class);
		$this->service->merge('hr', 2, 1);
	}

	public function testTriageCandidatesAreNotMerged(): void {
		$this->candidate(1, null);
		$this->candidate(2, null);
		$this->expectNothingMoved();
		$this->expectException(ValidationException::class);
		$this->service->merge('hr', 2, 1);
	}

	public function testACandidateIsNotMergedIntoItself(): void {
		$this->candidate(1, 5);
		$this->expectNothingMoved();
		$this->expectException(ValidationException::class);
		$this->service->merge('hr', 1, 1);
	}

	public function testAnonymizedCandidatesAreNotMerged(): void {
		$this->candidate(1, 5);
		$this->candidate(2, 5)->setAnonymizedAt(new \DateTime('2026-08-10'));
		$this->expectNothingMoved();
		$this->expectException(ValidationException::class);
		$this->service->merge('hr', 2, 1);
	}

	public function testMergingNeedsManageAccessBeforeAnythingMoves(): void {
		$this->candidate(1, 5);
		$this->candidate(2, 5);
		$this->permissions->method('assertCanManageCandidate')
			->willThrowException(new NotPermittedException('No management access to this candidate'));
		$this->expectNothingMoved();
		$this->expectException(NotPermittedException::class);
		$this->service->merge('observer', 2, 1);
	}

	public function testDetailListsOtherApplicationsTheUserMaySee(): void {
		$candidate = $this->candidate(1, 5);
		$visible = $this->candidate(2, 6);
		$hidden = $this->candidate(3, 7);
		$this->candidateMapper->method('findOtherApplications')->with('jane@example.com', 1)->willReturn([$visible, $hidden]);
		$this->permissions->method('canViewCandidate')
			->willReturnCallback(static fn (string $uid, Candidate $c) => $c->getId() !== 3);
		$this->openingMapper->method('findTitle')->willReturnCallback(static fn (?int $id) => 'Opening ' . $id);

		$detail = $this->service->get('manager', $candidate->getId());

		$this->assertSame([[
			'id' => 2,
			'openingId' => 6,
			'openingTitle' => 'Opening 6',
			'stage' => 'new',
			'createdAt' => '2026-08-01T00:00:00+00:00',
		]], $detail['otherApplications']);
	}

	public function testDestroyIsRecruiterOnlyAndRemovesTheCandidate(): void {
		$candidate = $this->candidate(4, 5);
		$this->permissions->expects($this->once())->method('assertRecruiter')->with('hr');
		$this->documents->expects($this->once())->method('deleteAllFor')->with(4);
		$this->notifications->expects($this->once())->method('purgeForCandidate')->with(4);
		$this->eventMapper->expects($this->once())->method('deleteForCandidate')->with(4);
		$this->candidateMapper->expects($this->once())->method('delete')->with($candidate);
		$this->service->destroy('hr', 4);
	}
}
