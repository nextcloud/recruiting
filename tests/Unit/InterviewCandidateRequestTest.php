<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Controller\PublicController;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewAttendee;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\InterviewSlotMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\CalendarService;
use OCA\Recruiting\Service\IngestionService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\PoolService;
use OCA\Recruiting\Service\SlotFinderService;
use OCA\Recruiting\Service\StageService;
use OCA\Recruiting\Service\TalkService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * "None of these times work" and "I need to reschedule" on the public
 * interview page: recorded and routed to the team, nothing is cancelled.
 */
class InterviewCandidateRequestTest extends TestCase {
	private CandidateMapper&MockObject $candidateMapper;
	private InterviewMapper&MockObject $interviewMapper;
	private TimelineService&MockObject $timeline;
	private NotificationService&MockObject $notifications;
	private CalendarService&MockObject $calendar;
	private InterviewService $service;
	private Candidate $candidate;

	protected function setUp(): void {
		$this->candidate = new Candidate();
		$this->candidate->setId(5);
		$this->candidate->setStage(Candidate::STAGE_INTERVIEW);
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->candidateMapper->method('find')->willReturnCallback(fn () => $this->candidate);
		$this->interviewMapper = $this->createMock(InterviewMapper::class);
		$attendee = new InterviewAttendee();
		$attendee->setUid('alice');
		$attendees = $this->createMock(InterviewAttendeeMapper::class);
		$attendees->method('findForInterview')->willReturn([$attendee]);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->calendar = $this->createMock(CalendarService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);

		$this->service = new InterviewService(
			$this->interviewMapper,
			$this->createMock(InterviewSlotMapper::class),
			$attendees,
			$this->candidateMapper,
			$this->createMock(OpeningMapper::class),
			$this->createMock(PermissionService::class),
			$this->createMock(SlotFinderService::class),
			$this->calendar,
			$this->createMock(TalkService::class),
			$this->createMock(MailService::class),
			$this->createMock(StageService::class),
			$this->timeline,
			$this->notifications,
			$this->createMock(ActivityPublisher::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
		);
	}

	private function interview(string $status): Interview {
		$interview = new Interview();
		$interview->setId(8);
		$interview->setCandidateId(5);
		$interview->setTitle('Tech talk');
		$interview->setStatus($status);
		$interview->setCreatedBy('boss');
		return $interview;
	}

	public function testDecliningProposedSlotsNotifiesTheTeam(): void {
		$this->timeline->expects($this->once())->method('record')->with(5, TimelineEvent::TYPE_INTERVIEW_SLOTS_DECLINED, null, [
			'title' => 'Tech talk',
			'message' => 'Next week works better',
		]);
		$this->notifications->expects($this->once())->method('notify')
			->with(['alice', 'boss'], NotificationService::SUBJECT_INTERVIEW_SLOTS_DECLINED, $this->candidate, null, ['interviewTitle' => 'Tech talk']);
		$this->interviewMapper->expects($this->never())->method('update');
		$this->service->declineSlots($this->interview(Interview::STATUS_PROPOSED), "  Next week works better \n");
	}

	public function testMessagesAreCapped(): void {
		$this->timeline->expects($this->once())->method('record')->with(
			$this->anything(), $this->anything(), $this->anything(),
			$this->callback(static fn (array $data) => mb_strlen($data['message']) === InterviewService::CANDIDATE_MESSAGE_LIMIT),
		);
		$this->service->declineSlots($this->interview(Interview::STATUS_PROPOSED), str_repeat('x', 5000));
	}

	public function testDecliningAConfirmedInterviewIsRefused(): void {
		$this->timeline->expects($this->never())->method('record');
		$this->expectException(ValidationException::class);
		$this->service->declineSlots($this->interview(Interview::STATUS_CONFIRMED), '');
	}

	public function testRescheduleRequestKeepsTheBooking(): void {
		$this->calendar->expects($this->never())->method('cancelEvent');
		$this->interviewMapper->expects($this->never())->method('update');
		$this->timeline->expects($this->once())->method('record')->with(5, TimelineEvent::TYPE_INTERVIEW_RESCHEDULE_REQUESTED, null, $this->anything());
		$this->notifications->expects($this->once())->method('notify')
			->with($this->anything(), NotificationService::SUBJECT_INTERVIEW_RESCHEDULE_REQUESTED);
		$this->service->requestReschedule($this->interview(Interview::STATUS_CONFIRMED), '');
	}

	public function testReschedulingAnUnconfirmedInterviewIsRefused(): void {
		$this->expectException(ValidationException::class);
		$this->service->requestReschedule($this->interview(Interview::STATUS_PROPOSED), '');
	}

	public function testClosedApplicationsCannotSendRequests(): void {
		$this->candidate->setStage(Candidate::STAGE_REJECTED);
		$this->notifications->expects($this->never())->method('notify');
		$this->expectException(ValidationException::class);
		$this->service->declineSlots($this->interview(Interview::STATUS_PROPOSED), '');
	}

	private function controller(InterviewMapper $mapper, InterviewService $interviews): PublicController {
		return new PublicController(
			'recruiting',
			$this->createMock(IRequest::class),
			$mapper,
			$interviews,
			$this->createMock(PoolService::class),
			$this->createMock(OpeningMapper::class),
			$this->createMock(IngestionService::class),
			$this->createMock(IInitialState::class),
			$this->createMock(Defaults::class),
			$this->createMock(IL10N::class),
			new NullLogger(),
		);
	}

	public function testEndpointsAnswerUnknownTokensWithAThrottled404(): void {
		$mapper = $this->createMock(InterviewMapper::class);
		$mapper->method('findByToken')->willReturn(null);
		$interviews = $this->createMock(InterviewService::class);
		$interviews->expects($this->never())->method('declineSlots');
		$interviews->expects($this->never())->method('requestReschedule');
		$controller = $this->controller($mapper, $interviews);
		foreach (['declineSlots', 'requestReschedule'] as $action) {
			$response = $controller->$action(str_repeat('a', 48), 'hi');
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertTrue($response->isThrottled());
		}
	}

	public function testEndpointsReportValidationErrors(): void {
		$mapper = $this->createMock(InterviewMapper::class);
		$mapper->method('findByToken')->willReturn($this->interview(Interview::STATUS_PROPOSED));
		$interviews = $this->createMock(InterviewService::class);
		$interviews->method('requestReschedule')->willThrowException(new ValidationException('Only a confirmed interview can be rescheduled'));
		$interviews->expects($this->once())->method('declineSlots');
		$controller = $this->controller($mapper, $interviews);
		$this->assertSame(['sent' => true], $controller->declineSlots(str_repeat('a', 48), 'hi')->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->requestReschedule(str_repeat('a', 48))->getStatus());
	}
}
