<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewAttendee;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\InterviewFeedback;
use OCA\Recruiting\Db\InterviewFeedbackMapper;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\InterviewFeedbackService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Feedback after an interview round, hidden from interviewers until they
 * gave their own (the vote rule).
 */
class InterviewFeedbackServiceTest extends TestCase {
	private const NOW = '2026-08-16T12:00:00+00:00';

	/** @var array<class-string, MockObject> */
	private array $mocks = [];
	private Candidate $candidate;
	private Interview $interview;
	/** @var InterviewFeedback[] */
	private array $stored = [];

	protected function setUp(): void {
		$this->mocks = [];
		$this->stored = [];
		$this->candidate = new Candidate();
		$this->candidate->setId(5);
		$this->candidate->setOpeningId(3);
		$this->candidate->setDisplayName('Jane Doe');
		$this->candidate->setStage(Candidate::STAGE_INTERVIEW);
		$this->interview = $this->interview(Interview::STATUS_DONE);

		$this->mock(ITimeFactory::class)->method('getDateTime')->willReturnCallback(static fn () => new \DateTime(self::NOW));
		$this->mock(IL10N::class)->method('t')->willReturnArgument(0);
		$this->mock(CandidateMapper::class)->method('find')->willReturnCallback(fn () => $this->candidate);
		$this->mock(InterviewMapper::class)->method('find')->willReturnCallback(fn () => $this->interview);
		$this->mock(OpeningMapper::class)->method('findTitle')->willReturn('Backend Developer');
		$this->mock(IUserManager::class)->method('getDisplayName')->willReturnCallback(static fn (string $uid) => ucfirst($uid));
		$attendees = [];
		foreach (['alice', 'bob'] as $uid) {
			$attendee = new InterviewAttendee();
			$attendee->setUid($uid);
			$attendees[] = $attendee;
		}
		$this->mock(InterviewAttendeeMapper::class)->method('findForInterview')->willReturn($attendees);
		$feedbackMapper = $this->mock(InterviewFeedbackMapper::class);
		$feedbackMapper->method('findForInterviews')->willReturnCallback(fn () => $this->stored);
		$feedbackMapper->method('findFeedback')->willReturnCallback(function (int $interviewId, string $uid) {
			foreach ($this->stored as $row) {
				if ($row->getUid() === $uid) {
					return $row;
				}
			}
			return null;
		});
		$feedbackMapper->method('insert')->willReturnCallback(function (InterviewFeedback $row) {
			$this->stored[] = $row;
			return $row;
		});
		$manager = new TeamMember();
		$manager->setUid('boss');
		$manager->setRole(TeamMember::ROLE_MANAGER);
		$interviewer = new TeamMember();
		$interviewer->setUid('alice');
		$interviewer->setRole(TeamMember::ROLE_INTERVIEWER);
		$this->mock(TeamMemberMapper::class)->method('findForOpening')->willReturn([$manager, $interviewer]);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T&MockObject
	 */
	private function mock(string $class): MockObject {
		return $this->mocks[$class] ??= $this->createMock($class);
	}

	private function service(): InterviewFeedbackService {
		$args = [];
		foreach ((new \ReflectionClass(InterviewFeedbackService::class))->getConstructor()->getParameters() as $parameter) {
			/** @var class-string $type */
			$type = (string)$parameter->getType();
			$args[] = $this->mock($type);
		}
		return new InterviewFeedbackService(...$args);
	}

	private function interview(string $status, string $endAt = '2026-08-15T11:00:00+00:00'): Interview {
		$interview = new Interview();
		$interview->setId(8);
		$interview->setCandidateId(5);
		$interview->setTitle('Tech talk');
		$interview->setStatus($status);
		$interview->setCreatedBy('recruiter');
		$interview->setStartAt((new \DateTime($endAt))->modify('-1 hour'));
		$interview->setEndAt(new \DateTime($endAt));
		return $interview;
	}

	private function feedback(string $uid, string $verdict, string $notes = ''): InterviewFeedback {
		$row = new InterviewFeedback();
		$row->setInterviewId(8);
		$row->setUid($uid);
		$row->setVerdict($verdict);
		$row->setNotes($notes !== '' ? $notes : null);
		$row->setUpdatedAt(new \DateTime(self::NOW));
		return $row;
	}

	private function allowView(): void {
		$this->mock(PermissionService::class)->method('canViewCandidate')->willReturn(true);
	}

	public function testRoundIsOverWhenDoneOrConfirmedAndEnded(): void {
		$service = $this->service();
		$this->assertTrue($service->isOver($this->interview(Interview::STATUS_DONE)));
		$this->assertTrue($service->isOver($this->interview(Interview::STATUS_CONFIRMED)));
		$this->assertFalse($service->isOver($this->interview(Interview::STATUS_CONFIRMED, '2026-08-16T13:00:00+00:00')));
		$this->assertFalse($service->isOver($this->interview(Interview::STATUS_PROPOSED)));
		$this->assertFalse($service->isOver($this->interview(Interview::STATUS_CANCELLED)));
	}

	public function testFirstFeedbackIsStoredAndLoggedWithoutTheVerdict(): void {
		$this->allowView();
		$this->mock(TimelineService::class)->expects($this->once())->method('record')
			->with(5, TimelineEvent::TYPE_INTERVIEW_FEEDBACK, 'alice', ['title' => 'Tech talk']);
		$this->mock(NotificationService::class)->expects($this->never())->method('notify');

		$summary = $this->service()->submit('alice', 8, 'yes', '  Strong on systems design ');

		$this->assertCount(1, $this->stored);
		$this->assertSame('yes', $this->stored[0]->getVerdict());
		$this->assertSame('Strong on systems design', $this->stored[0]->getNotes());
		$this->assertSame('yes', $summary['myFeedback']['verdict']);
	}

	public function testUpdatingDoesNotLogAgain(): void {
		$this->allowView();
		$this->stored = [$this->feedback('alice', 'maybe')];
		$this->mock(TimelineService::class)->expects($this->never())->method('record');
		$this->mock(InterviewFeedbackMapper::class)->expects($this->once())->method('update');
		$this->service()->submit('alice', 8, 'yes', '');
		$this->assertSame('yes', $this->stored[0]->getVerdict());
		$this->assertNull($this->stored[0]->getNotes());
	}

	public function testLastFeedbackNotifiesManagersAndOrganizer(): void {
		$this->allowView();
		$this->stored = [$this->feedback('alice', 'yes')];
		$this->mock(NotificationService::class)->expects($this->once())->method('notify')
			->with(['boss', 'recruiter'], NotificationService::SUBJECT_INTERVIEW_FEEDBACK_COMPLETE, $this->candidate, 'bob', ['interviewTitle' => 'Tech talk']);
		$this->service()->submit('bob', 8, 'maybe', '');
	}

	public function testOnlyTheRoundsInterviewersMayGiveFeedback(): void {
		$this->allowView();
		$this->mock(InterviewFeedbackMapper::class)->expects($this->never())->method('insert');
		$this->expectException(NotPermittedException::class);
		$this->service()->submit('boss', 8, 'yes', '');
	}

	public function testViewAccessIsCheckedFirst(): void {
		$this->mock(PermissionService::class)->method('assertCanViewCandidate')->willThrowException(new NotPermittedException('no'));
		$this->mock(InterviewFeedbackMapper::class)->expects($this->never())->method('insert');
		$this->expectException(NotPermittedException::class);
		$this->service()->submit('alice', 8, 'yes', '');
	}

	public function testFeedbackOpensAfterTheInterview(): void {
		$this->allowView();
		$this->interview = $this->interview(Interview::STATUS_CONFIRMED, '2026-08-20T11:00:00+00:00');
		$this->expectException(ValidationException::class);
		$this->service()->submit('alice', 8, 'yes', '');
	}

	public function testANoNeedsNotes(): void {
		$this->allowView();
		$this->expectException(ValidationException::class);
		$this->service()->submit('alice', 8, 'no', '   ');
	}

	public function testUnknownVerdictIsRefused(): void {
		$this->allowView();
		$this->expectException(ValidationException::class);
		$this->service()->submit('alice', 8, 'great', '');
	}

	public function testAnonymizedCandidatesTakeNoFeedback(): void {
		$this->allowView();
		$this->candidate->setAnonymizedAt(new \DateTime(self::NOW));
		$this->expectException(ValidationException::class);
		$this->service()->submit('alice', 8, 'yes', '');
	}

	public function testOthersFeedbackIsHiddenUntilYouGaveYours(): void {
		$this->stored = [$this->feedback('alice', 'no', 'Weak on testing')];
		$permissions = $this->mock(PermissionService::class);
		$permissions->method('maySeeVerdicts')->willReturnCallback(static fn (string $uid, Candidate $c, bool $hasOwn) => $hasOwn);

		$summary = $this->service()->summaries('bob', $this->candidate, [$this->interview])[8];
		$this->assertTrue($summary['feedbackOpen']);
		$this->assertTrue($summary['canGiveFeedback']);
		$this->assertNull($summary['myFeedback']);
		$this->assertSame(2, $summary['attendeeCount']);
		$this->assertSame('Alice', $summary['feedback'][0]['displayName']);
		$this->assertNull($summary['feedback'][0]['verdict']);
		$this->assertNull($summary['feedback'][0]['notes']);

		$this->stored[] = $this->feedback('bob', 'yes');
		$summary = $this->service()->summaries('bob', $this->candidate, [$this->interview])[8];
		$this->assertSame('no', $summary['feedback'][0]['verdict']);
		$this->assertSame('Weak on testing', $summary['feedback'][0]['notes']);
	}

	public function testManagersSeeEverythingButCannotGiveFeedbackOnRoundsTheyMissed(): void {
		$this->stored = [$this->feedback('alice', 'yes', 'Great')];
		$this->mock(PermissionService::class)->method('maySeeVerdicts')->willReturn(true);
		$summary = $this->service()->summaries('boss', $this->candidate, [$this->interview])[8];
		$this->assertSame('yes', $summary['feedback'][0]['verdict']);
		$this->assertFalse($summary['canGiveFeedback']);
	}

	public function testUpcomingRoundsAreNotOpenForFeedback(): void {
		$this->interview = $this->interview(Interview::STATUS_CONFIRMED, '2026-08-20T11:00:00+00:00');
		$summary = $this->service()->summaries('alice', $this->candidate, [$this->interview])[8];
		$this->assertFalse($summary['feedbackOpen']);
		$this->assertFalse($summary['canGiveFeedback']);
	}

	public function testOwedListsRecentRoundsWithoutFeedback(): void {
		$this->mock(InterviewMapper::class)->expects($this->once())->method('findFeedbackOwed')
			->with('alice', new \DateTime('2026-07-17T12:00:00+00:00'), new \DateTime(self::NOW))
			->willReturn([$this->interview]);
		$this->allowView();

		$owed = $this->service()->owed('alice');
		$this->assertCount(1, $owed);
		$this->assertSame(['id' => 5, 'displayName' => 'Jane Doe', 'openingId' => 3, 'openingTitle' => 'Backend Developer'], $owed[0]['candidate']);
	}

	public function testOwedSkipsAnonymizedAndInaccessibleCandidates(): void {
		$this->mock(InterviewMapper::class)->method('findFeedbackOwed')->willReturn([$this->interview]);
		$this->mock(PermissionService::class)->method('canViewCandidate')->willReturn(false);
		$this->assertSame([], $this->service()->owed('alice'));

		$this->mocks[PermissionService::class] = $this->createMock(PermissionService::class);
		$this->allowView();
		$this->candidate->setAnonymizedAt(new \DateTime(self::NOW));
		$this->assertSame([], $this->service()->owed('alice'));
	}

	public function testPrivacyOperationsReachTheMapper(): void {
		$mapper = $this->mock(InterviewFeedbackMapper::class);
		$mapper->expects($this->once())->method('deleteForCandidate')->with(5);
		$mapper->expects($this->once())->method('stripNotesForCandidate')->with(5);
		$this->service()->deleteForCandidate(5);
		$this->service()->stripNotesForCandidate(5);
	}
}
