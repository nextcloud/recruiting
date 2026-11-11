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
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\InterviewSlot;
use OCA\Recruiting\Db\InterviewSlotMapper;
use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\CalendarService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\SlotFinderService;
use OCA\Recruiting\Service\TalkService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Rescheduling, cancelling with the candidate, calendar holds and the
 * free/busy re-check on confirmation.
 */
class InterviewSchedulingTest extends TestCase {
	private const NOW = '2026-08-16T12:00:00+00:00';

	/** @var array<class-string, MockObject> */
	private array $mocks = [];
	private Candidate $candidate;
	/** @var InterviewSlot[] */
	private array $slots = [];
	private int $nextSlotId = 100;

	protected function setUp(): void {
		$this->mocks = [];
		$this->slots = [];
		$this->candidate = new Candidate();
		$this->candidate->setId(5);
		$this->candidate->setOpeningId(3);
		$this->candidate->setDisplayName('Jane Doe');
		$this->candidate->setEmail('jane@example.org');
		$this->candidate->setStage(Candidate::STAGE_INTERVIEW);

		$this->mock(ITimeFactory::class)->method('getDateTime')->willReturnCallback(static fn () => new \DateTime(self::NOW));
		$this->mock(IL10N::class)->method('t')->willReturnCallback(
			static fn (string $text, $params = []) => vsprintf($text, is_array($params) ? $params : [$params]),
		);
		$this->mock(CandidateMapper::class)->method('find')->willReturnCallback(fn () => $this->candidate);
		$this->mock(OpeningMapper::class)->method('findTitle')->willReturn('Backend Developer');
		$this->mock(ISecureRandom::class)->method('generate')->willReturn(str_repeat('t', 48));
		$this->mock(IURLGenerator::class)->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []) => 'https://cloud.example/' . $route . (isset($params['token']) ? '/' . $params['token'] : ''),
		);
		$this->mock(IUserManager::class)->method('get')->willReturnCallback(function (string $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getEMailAddress')->willReturn($uid . '@example.org');
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			return $user;
		});
		$attendees = [];
		foreach (['alice', 'bob'] as $uid) {
			$attendee = new InterviewAttendee();
			$attendee->setUid($uid);
			$attendees[] = $attendee;
		}
		$this->mock(InterviewAttendeeMapper::class)->method('findForInterview')->willReturn($attendees);
		$slotMapper = $this->mock(InterviewSlotMapper::class);
		$slotMapper->method('findForInterview')->willReturnCallback(fn () => $this->slots);
		$slotMapper->method('insert')->willReturnCallback(function (InterviewSlot $slot) {
			$slot->setId($this->nextSlotId++);
			$this->slots[] = $slot;
			return $slot;
		});
		$slotMapper->method('deleteForInterview')->willReturnCallback(function () {
			$this->slots = [];
		});
		$this->mock(InterviewMapper::class)->method('insert')->willReturnCallback(static function (Interview $interview) {
			$interview->setId(8);
			return $interview;
		});
		$this->mock(MailService::class)->method('preview')->willReturnCallback(
			static fn ($candidate, $title, string $type) => ['subject' => 'Subject ' . $type, 'body' => 'Body', 'to' => 'jane@example.org'],
		);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T&MockObject
	 */
	private function mock(string $class): MockObject {
		return $this->mocks[$class] ??= $this->createMock($class);
	}

	private function service(): InterviewService {
		$args = [];
		foreach ((new \ReflectionClass(InterviewService::class))->getConstructor()->getParameters() as $parameter) {
			/** @var class-string $type */
			$type = (string)$parameter->getType();
			$args[] = $this->mock($type);
		}
		return new InterviewService(...$args);
	}

	private function interview(string $status, bool $holdSlots = true): Interview {
		$interview = new Interview();
		$interview->setId(8);
		$interview->setCandidateId(5);
		$interview->setTitle('Tech talk');
		$interview->setStatus($status);
		$interview->setDurationMin(60);
		$interview->setPublicToken(str_repeat('o', 48));
		$interview->setCreatedBy('boss');
		$interview->setHoldSlots($holdSlots);
		$this->mock(InterviewMapper::class)->method('find')->willReturn($interview);
		return $interview;
	}

	private function slot(int $id, string $start, int $minutes = 60): InterviewSlot {
		$slot = new InterviewSlot();
		$slot->setId($id);
		$slot->setInterviewId(8);
		$slot->setStartAt(new \DateTime($start));
		$slot->setEndAt((new \DateTime($start))->modify('+' . $minutes . ' minutes'));
		$this->slots[] = $slot;
		return $slot;
	}

	private function allowManage(): void {
		$this->mock(PermissionService::class)->method('roleForOpening')->willReturn('manager');
	}

	/**
	 * @return \ArrayObject<int, string> "uid:holdUid" for every hold call of $method
	 */
	private function recordHolds(string $method): \ArrayObject {
		$calls = new \ArrayObject();
		$this->mock(CalendarService::class)->method($method)->willReturnCallback(function ($user, string $uid) use ($calls) {
			$calls[] = ($user instanceof IUser ? $user->getUID() : $user) . ':' . $uid;
			return true;
		});
		return $calls;
	}

	public function testCreatingHoldsEverySlotForEveryInterviewer(): void {
		$this->allowManage();
		$holds = $this->recordHolds('createHold');
		$this->service()->create('boss', 5, [
			'attendees' => ['alice', 'bob'],
			'slots' => [['start' => '2026-08-18T09:00:00Z'], ['start' => '2026-08-19T09:00:00Z']],
		]);
		$this->assertSame([
			'alice:recruiting-hold-100', 'alice:recruiting-hold-101',
			'bob:recruiting-hold-100', 'bob:recruiting-hold-101',
		], $holds->getArrayCopy());
	}

	public function testHoldsCanBeSwitchedOff(): void {
		$this->allowManage();
		$this->mock(CalendarService::class)->expects($this->never())->method('createHold');
		$result = $this->service()->create('boss', 5, [
			'attendees' => ['alice'],
			'slots' => [['start' => '2026-08-18T09:00:00Z']],
			'holdSlots' => false,
		]);
		$this->assertFalse($result['interview']['holdSlots']);
	}

	public function testSendingTheInvitationStartsTheNudgeClock(): void {
		$interview = $this->interview(Interview::STATUS_PROPOSED);
		$this->mock(InterviewMapper::class)->expects($this->once())->method('update')
			->with($this->callback(static fn (Interview $i) => $i->getInvitedAt() == new \DateTime(self::NOW)));
		$this->service()->sendInvite('boss', 8, 'Hi', 'Pick a time: https://cloud.example/recruiting.public.showInterview/' . $interview->getPublicToken());
	}

	public function testReschedulingAConfirmedInterviewReleasesTheBookingAndKeepsTheLink(): void {
		$this->allowManage();
		$interview = $this->interview(Interview::STATUS_CONFIRMED);
		$interview->setStartAt(new \DateTime('2026-08-18T09:00:00Z'));
		$interview->setEndAt(new \DateTime('2026-08-18T10:00:00Z'));
		$interview->setCalendarUid('event-1.ics');
		$interview->setTalkToken('room1');
		$interview->setTalkLink('https://cloud.example/call/room1');
		$interview->setReminderSentAt(new \DateTime(self::NOW));
		$this->slot(1, '2026-08-18T09:00:00Z')->setChosen(true);

		$calendar = $this->mock(CalendarService::class);
		$calendar->expects($this->once())->method('cancelEvent')->with($this->anything(), 'event-1.ics', ['alice', 'bob']);
		$removed = $this->recordHolds('removeHold');
		$created = $this->recordHolds('createHold');
		$this->mock(TalkService::class)->expects($this->never())->method('deleteRoom');
		$this->mock(TimelineService::class)->expects($this->once())->method('record')
			->with(5, TimelineEvent::TYPE_INTERVIEW_RESCHEDULED, 'boss', ['title' => 'Tech talk', 'slotCount' => 1, 'previousStartAt' => '2026-08-18T09:00:00+00:00']);
		$this->mock(NotificationService::class)->expects($this->once())->method('notify')
			->with(['alice', 'bob'], NotificationService::SUBJECT_INTERVIEW_RESCHEDULED, $this->candidate, 'boss', ['interviewTitle' => 'Tech talk']);

		$result = $this->service()->reschedule('boss', 8, ['slots' => [['start' => '2026-08-20T13:00:00Z']]]);

		$this->assertSame(Interview::STATUS_PROPOSED, $interview->getStatus());
		$this->assertNull($interview->getStartAt());
		$this->assertNull($interview->getReminderSentAt());
		$this->assertSame('', $interview->getCalendarUid());
		$this->assertSame('room1', $interview->getTalkToken(), 'the Talk room is kept for the new time');
		$this->assertSame(str_repeat('o', 48), $interview->getPublicToken());
		$this->assertSame(['alice:recruiting-hold-1', 'bob:recruiting-hold-1'], $removed->getArrayCopy());
		$this->assertSame(['alice:recruiting-hold-100', 'bob:recruiting-hold-100'], $created->getArrayCopy());
		$this->assertCount(1, $this->slots);
		$this->assertSame('2026-08-20T13:00:00+00:00', $this->slots[0]->getStartAt()->format('c'));
		$this->assertSame('Subject ' . MailTemplate::TYPE_INTERVIEW_RESCHEDULE, $result['inviteDraft']['subject']);
	}

	public function testReschedulingCanSwitchHoldsOff(): void {
		$this->allowManage();
		$interview = $this->interview(Interview::STATUS_PROPOSED);
		$this->slot(1, '2026-08-18T09:00:00Z');
		$this->mock(CalendarService::class)->expects($this->never())->method('createHold');
		$this->service()->reschedule('boss', 8, ['slots' => [['start' => '2026-08-20T13:00:00Z']], 'holdSlots' => false]);
		$this->assertFalse($interview->getHoldSlots());
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function finishedStatuses(): array {
		return ['cancelled' => [Interview::STATUS_CANCELLED], 'done' => [Interview::STATUS_DONE]];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('finishedStatuses')]
	public function testFinishedInterviewsCannotBeRescheduled(string $status): void {
		$this->allowManage();
		$this->interview($status);
		$this->mock(InterviewSlotMapper::class)->expects($this->never())->method('deleteForInterview');
		$this->expectException(ValidationException::class);
		$this->service()->reschedule('boss', 8, ['slots' => [['start' => '2026-08-20T13:00:00Z']]]);
	}

	public function testClosedApplicationsCannotBeRescheduled(): void {
		$this->allowManage();
		$this->interview(Interview::STATUS_PROPOSED);
		$this->candidate->setStage(Candidate::STAGE_WITHDRAWN);
		$this->expectException(ValidationException::class);
		$this->service()->reschedule('boss', 8, ['slots' => [['start' => '2026-08-20T13:00:00Z']]]);
	}

	public function testReschedulingNeedsManageAccessFirst(): void {
		$this->interview(Interview::STATUS_PROPOSED);
		$this->mock(PermissionService::class)->method('assertCanManageCandidate')->willThrowException(new NotPermittedException('no'));
		$this->mock(InterviewSlotMapper::class)->expects($this->never())->method('deleteForInterview');
		$this->expectException(NotPermittedException::class);
		$this->service()->reschedule('eve', 8, ['slots' => [['start' => '2026-08-20T13:00:00Z']]]);
	}

	public function testATakenTimeIsMarkedUnavailableAndTheInterviewStaysOpen(): void {
		$interview = $this->interview(Interview::STATUS_PROPOSED);
		$chosen = $this->slot(1, '2026-08-18T09:00:00Z');
		$this->slot(2, '2026-08-18T09:30:00Z');
		$this->slot(3, '2026-08-19T09:00:00Z');
		$chosenCopy = clone $chosen;
		$slotMapper = $this->mock(InterviewSlotMapper::class);
		$slotMapper->method('find')->willReturn($chosenCopy);
		$slotMapper->expects($this->once())->method('update')
			->with($this->callback(static fn (InterviewSlot $slot) => $slot->getId() === 1 && $slot->getUnavailable()));
		$interviewMapper = $this->mock(InterviewMapper::class);
		$interviewMapper->method('claimForConfirmation')->willReturn(true);
		$interviewMapper->expects($this->once())->method('releaseConfirmation')->with(8);
		$interviewMapper->expects($this->never())->method('update');
		$this->mock(SlotFinderService::class)->expects($this->once())->method('attendeesFree')
			->with($this->anything(), ['alice', 'bob'])
			->willReturn(false);
		$removed = $this->recordHolds('removeHold');
		$created = $this->recordHolds('createHold');
		$this->mock(CalendarService::class)->expects($this->never())->method('createEvent');

		try {
			$this->service()->confirmSlot($interview, 1);
			$this->fail('expected the slot to be refused');
		} catch (ValidationException $e) {
			$this->assertSame('This time is no longer available, please pick another one.', $e->getMessage());
		}
		// Only this interview's own holds overlapping the chosen time were lifted for the check …
		$this->assertSame(['alice:recruiting-hold-1', 'alice:recruiting-hold-2', 'bob:recruiting-hold-1', 'bob:recruiting-hold-2'], $removed->getArrayCopy());
		// … and the still pickable one is held again
		$this->assertSame(['alice:recruiting-hold-2', 'bob:recruiting-hold-2'], $created->getArrayCopy());
	}

	public function testConfirmingReleasesAllHoldsReusesTheTalkRoomAndLinksTheCandidate(): void {
		$interview = $this->interview(Interview::STATUS_PROPOSED);
		$interview->setIsVideo(true);
		$interview->setTalkToken('room1');
		$interview->setTalkLink('https://cloud.example/call/room1');
		$chosen = $this->slot(1, '2026-08-18T09:00:00Z');
		$this->slot(2, '2026-08-19T09:00:00Z');
		$this->mock(InterviewSlotMapper::class)->method('find')->willReturn($chosen);
		$this->mock(InterviewMapper::class)->method('claimForConfirmation')->willReturn(true);
		$this->mock(SlotFinderService::class)->method('attendeesFree')->willReturn(true);
		$removed = $this->recordHolds('removeHold');
		$this->mock(TalkService::class)->expects($this->never())->method('createRoom');
		$this->mock(CalendarService::class)->expects($this->once())->method('createEvent')
			->with(
				$this->anything(),
				$this->anything(),
				$this->callback(static fn (string $description) => str_contains($description, 'Open in Recruiting: https://cloud.example/recruiting.page.index#/candidate/5')),
			)
			->willReturn('event.ics');

		$this->service()->confirmSlot($interview, 1);

		$this->assertSame(Interview::STATUS_CONFIRMED, $interview->getStatus());
		$this->assertSame('event.ics', $interview->getCalendarUid());
		$removedHolds = $removed->getArrayCopy();
		sort($removedHolds);
		$this->assertSame(['alice:recruiting-hold-1', 'alice:recruiting-hold-2', 'bob:recruiting-hold-1', 'bob:recruiting-hold-2'], $removedHolds);
	}

	public function testUnavailableSlotsAreRefusedBeforeAnything(): void {
		$interview = $this->interview(Interview::STATUS_PROPOSED);
		$slot = $this->slot(1, '2026-08-18T09:00:00Z');
		$slot->setUnavailable(true);
		$this->mock(InterviewSlotMapper::class)->method('find')->willReturn($slot);
		$this->mock(InterviewMapper::class)->expects($this->never())->method('claimForConfirmation');
		$this->expectException(ValidationException::class);
		$this->service()->confirmSlot($interview, 1);
	}

	public function testPublicPageHidesUnavailableSlots(): void {
		$interview = $this->interview(Interview::STATUS_PROPOSED);
		$this->slot(1, '2026-08-18T09:00:00Z')->setUnavailable(true);
		$this->slot(2, '2026-08-19T09:00:00Z');
		$this->slot(3, '2026-08-10T09:00:00Z');
		$data = $this->service()->publicData($interview);
		$this->assertSame([2], array_map(static fn (array $slot) => $slot['id'], $data['slots']));
	}

	public function testCancellingWithAMailSendsItFirst(): void {
		$this->interview(Interview::STATUS_PROPOSED);
		$this->slot(1, '2026-08-18T09:00:00Z');
		$order = [];
		$this->mock(MailService::class)->expects($this->once())->method('sendToCandidate')
			->with($this->candidate, 'Sorry', 'We have to cancel', 'boss')
			->willReturnCallback(function () use (&$order) {
				$order[] = 'mail';
			});
		$this->mock(InterviewMapper::class)->method('update')->willReturnCallback(function (Interview $interview) use (&$order) {
			$order[] = $interview->getStatus();
			return $interview;
		});
		$removed = $this->recordHolds('removeHold');

		$this->service()->cancel('boss', 8, 'Sorry', 'We have to cancel');

		$this->assertSame(['mail', Interview::STATUS_CANCELLED], $order);
		$this->assertSame(['alice:recruiting-hold-1', 'bob:recruiting-hold-1'], $removed->getArrayCopy());
	}

	public function testAFailedMailLeavesTheInterviewAlone(): void {
		$this->interview(Interview::STATUS_CONFIRMED);
		$this->mock(MailService::class)->method('sendToCandidate')->willThrowException(new ValidationException('SMTP down'));
		$this->mock(InterviewMapper::class)->expects($this->never())->method('update');
		$this->expectException(ValidationException::class);
		$this->service()->cancel('boss', 8, 'Sorry', 'We have to cancel');
	}

	public function testNoMailForAnInterviewThatIsAlreadyCancelled(): void {
		$this->interview(Interview::STATUS_CANCELLED);
		$this->mock(MailService::class)->expects($this->never())->method('sendToCandidate');
		$this->expectException(ValidationException::class);
		$this->service()->cancel('boss', 8, 'Sorry', 'We have to cancel');
	}

	public function testCancellingWithoutMail(): void {
		$interview = $this->interview(Interview::STATUS_PROPOSED);
		$this->mock(MailService::class)->expects($this->never())->method('sendToCandidate');
		$this->service()->cancel('boss', 8);
		$this->assertSame(Interview::STATUS_CANCELLED, $interview->getStatus());
	}

	public function testCancelPreviewUsesTheCancellationTemplate(): void {
		$this->interview(Interview::STATUS_PROPOSED);
		$this->mock(MailService::class)->expects($this->once())->method('preview')
			->with($this->candidate, 'Backend Developer', MailTemplate::TYPE_INTERVIEW_CANCELLED, null, 'boss', ['interview_title' => 'Tech talk']);
		$this->assertSame('Subject ' . MailTemplate::TYPE_INTERVIEW_CANCELLED, $this->service()->cancelPreview('boss', 8)['subject']);
	}

	public function testCancelPreviewNeedsManageAccess(): void {
		$this->interview(Interview::STATUS_PROPOSED);
		$this->mock(PermissionService::class)->method('assertCanManageCandidate')->willThrowException(new NotPermittedException('no'));
		$this->mock(MailService::class)->expects($this->never())->method('preview');
		$this->expectException(NotPermittedException::class);
		$this->service()->cancelPreview('eve', 8);
	}

	public function testWithdrawalCancelsOnlyOpenInterviews(): void {
		$make = function (int $id, string $status, ?string $end = null): Interview {
			$interview = new Interview();
			$interview->setId($id);
			$interview->setCandidateId(5);
			$interview->setTitle('Round ' . $id);
			$interview->setStatus($status);
			$interview->setCreatedBy('boss');
			$interview->setEndAt($end !== null ? new \DateTime($end) : null);
			return $interview;
		};
		$proposed = $make(1, Interview::STATUS_PROPOSED);
		$upcoming = $make(2, Interview::STATUS_CONFIRMED, '2026-08-20T10:00:00Z');
		$past = $make(3, Interview::STATUS_CONFIRMED, '2026-08-10T10:00:00Z');
		$done = $make(4, Interview::STATUS_DONE, '2026-08-09T10:00:00Z');
		$this->mock(InterviewMapper::class)->method('findForCandidate')->willReturn([$proposed, $upcoming, $past, $done]);
		$this->mock(NotificationService::class)->expects($this->exactly(2))->method('notify')
			->with(['alice', 'bob'], NotificationService::SUBJECT_INTERVIEW_CANCELLED, $this->candidate, null, $this->anything());

		$this->assertSame(2, $this->service()->cancelOpenFor($this->candidate, null));
		$this->assertSame(Interview::STATUS_CANCELLED, $proposed->getStatus());
		$this->assertSame(Interview::STATUS_CANCELLED, $upcoming->getStatus());
		$this->assertSame(Interview::STATUS_CONFIRMED, $past->getStatus());
		$this->assertSame(Interview::STATUS_DONE, $done->getStatus());
	}

	public function testPastInterviewsAreMarkedDone(): void {
		$this->mock(InterviewMapper::class)->expects($this->once())->method('markEndedAsDone')
			->with(new \DateTime(self::NOW))->willReturn(3);
		$this->assertSame(3, $this->service()->markPastAsDone());
	}
}
