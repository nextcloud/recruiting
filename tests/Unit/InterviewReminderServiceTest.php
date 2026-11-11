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
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\CandidateTimeFormatter;
use OCA\Recruiting\Service\InterviewReminderService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\MailService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class InterviewReminderServiceTest extends TestCase {
	private InterviewMapper&MockObject $interviewMapper;
	private CandidateMapper&MockObject $candidateMapper;
	private MailService&MockObject $mail;
	private InterviewReminderService $service;
	private Candidate $candidate;

	protected function setUp(): void {
		$this->interviewMapper = $this->createMock(InterviewMapper::class);
		$this->candidate = new Candidate();
		$this->candidate->setId(5);
		$this->candidate->setEmail('ada@example.org');
		$this->candidate->setStage(Candidate::STAGE_INTERVIEW);
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->candidateMapper->method('find')->willReturnCallback(fn () => $this->candidate);
		$this->mail = $this->createMock(MailService::class);
		$this->mail->method('preview')->willReturn(['subject' => 'Reminder', 'body' => 'See you', 'to' => 'ada@example.org']);
		$interviews = $this->createMock(InterviewService::class);
		$interviews->method('publicUrl')->willReturn('https://cloud.example/i/tok');
		$formatter = $this->createMock(CandidateTimeFormatter::class);
		$formatter->method('format')->willReturn(['date' => 'Wednesday, November 11, 2026', 'time' => '12:00 (Europe/Berlin)']);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-11-10T12:00:00Z'));

		$this->service = new InterviewReminderService(
			$this->interviewMapper,
			$this->candidateMapper,
			$this->createMock(OpeningMapper::class),
			$interviews,
			$this->mail,
			$formatter,
			$time,
			new NullLogger(),
		);
	}

	private function interview(string $talkLink = '', string $location = ''): Interview {
		$interview = new Interview();
		$interview->setId(8);
		$interview->setCandidateId(5);
		$interview->setTitle('Tech talk');
		$interview->setStatus(Interview::STATUS_CONFIRMED);
		$interview->setStartAt(new \DateTime('2026-11-11T11:00:00Z'));
		$interview->setTalkLink($talkLink);
		$interview->setLocation($location);
		$interview->setCreatedBy('boss');
		return $interview;
	}

	public function testTheWindowIsTwentyThreeToTwentyFiveHoursAhead(): void {
		$this->interviewMapper->expects($this->once())->method('findDueReminders')
			->with(new \DateTime('2026-11-11T11:00:00Z'), new \DateTime('2026-11-11T13:00:00Z'))
			->willReturn([]);
		$this->assertSame(0, $this->service->sendDue());
	}

	public function testReminderIsMailedOnceWithTheInterviewDetails(): void {
		$this->interviewMapper->method('findDueReminders')->willReturn([$this->interview('https://cloud.example/call/x')]);
		$this->interviewMapper->expects($this->once())->method('claimReminder')->with(8)->willReturn(true);
		$this->interviewMapper->expects($this->never())->method('releaseReminder');
		$this->mail->expects($this->once())->method('preview')->with(
			$this->candidate, $this->anything(), MailTemplate::TYPE_INTERVIEW_REMINDER, null, null,
			[
				'interview_title' => 'Tech talk',
				'interview_link' => 'https://cloud.example/i/tok',
				'interview_date' => 'Wednesday, November 11, 2026',
				'interview_time' => '12:00 (Europe/Berlin)',
				'interview_location' => 'https://cloud.example/call/x',
			],
		);
		$this->mail->expects($this->once())->method('sendToCandidate')->with($this->candidate, 'Reminder', 'See you', null);
		$this->assertSame(1, $this->service->sendDue());
	}

	public function testTheReminderIsDatedInTheCandidatesLanguage(): void {
		$this->interviewMapper->method('findDueReminders')->willReturn([$this->interview()]);
		$this->interviewMapper->method('claimReminder')->willReturn(true);
		$this->mail->expects($this->once())->method('l10nFor')->with($this->candidate);
		$this->assertSame(1, $this->service->sendDue());
	}

	public function testAlreadyClaimedRemindersAreSkipped(): void {
		$this->interviewMapper->method('findDueReminders')->willReturn([$this->interview()]);
		$this->interviewMapper->method('claimReminder')->willReturn(false);
		$this->mail->expects($this->never())->method('sendToCandidate');
		$this->assertSame(0, $this->service->sendDue());
	}

	public function testAFailedSendIsReleasedForTheNextRun(): void {
		$this->interviewMapper->method('findDueReminders')->willReturn([$this->interview('', 'Room 4')]);
		$this->interviewMapper->method('claimReminder')->willReturn(true);
		$this->mail->method('sendToCandidate')->willThrowException(new ValidationException('SMTP down'));
		$this->interviewMapper->expects($this->once())->method('releaseReminder')->with(8);
		$this->assertSame(0, $this->service->sendDue());
	}

	public function testClosedOrUnreachableCandidatesGetNoReminder(): void {
		$this->interviewMapper->method('findDueReminders')->willReturn([$this->interview()]);
		$this->interviewMapper->expects($this->never())->method('claimReminder');
		$this->candidate->setStage(Candidate::STAGE_WITHDRAWN);
		$this->assertSame(0, $this->service->sendDue());
		$this->candidate->setStage(Candidate::STAGE_INTERVIEW);
		$this->candidate->setEmail('');
		$this->assertSame(0, $this->service->sendDue());
	}
}
