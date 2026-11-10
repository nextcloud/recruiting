<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Document;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\CandidateStatus;
use OCA\Recruiting\Service\CandidateTimeFormatter;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\PortalService;
use OCA\Recruiting\Service\StageService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PortalServiceTest extends TestCase {
	private CandidateMapper&MockObject $candidateMapper;
	private InterviewMapper&MockObject $interviewMapper;
	private DocumentMapper&MockObject $documentMapper;
	private DocumentService&MockObject $documents;
	private StageService&MockObject $stages;
	private TimelineService&MockObject $timeline;
	private NotificationService&MockObject $notifications;
	private TeamMemberMapper&MockObject $teamMapper;
	private PortalService $service;

	protected function setUp(): void {
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->interviewMapper = $this->createMock(InterviewMapper::class);
		$this->documentMapper = $this->createMock(DocumentMapper::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->stages = $this->createMock(StageService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->teamMapper = $this->createMock(TeamMemberMapper::class);

		$openings = $this->createMock(OpeningMapper::class);
		$openings->method('findTitle')->willReturn('Backend Engineer');
		$config = $this->createMock(ConfigService::class);
		$config->method('getHrGroup')->willReturn('hr');
		$hrUser = $this->createMock(IUser::class);
		$hrUser->method('getUID')->willReturn('hr-anna');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$hrUser]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('hr')->willReturn($group);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params) => 'https://cloud.example/' . $route . '/' . $params['token'],
		);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('p', 48));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-11-10T12:00:00Z'));
		$formatter = $this->createMock(CandidateTimeFormatter::class);
		$formatter->method('format')->willReturn(['date' => 'Thursday, November 12, 2026', 'time' => '10:00 (UTC)']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []) => vsprintf($s, $p));

		$this->service = new PortalService(
			$this->candidateMapper, $this->interviewMapper, $openings, $this->documentMapper,
			$this->documents, $this->stages, $this->timeline, $this->notifications, $this->teamMapper,
			$config, $formatter, $groups, $urls, $random, $time, $l10n,
		);
	}

	private function candidate(string $stage = Candidate::STAGE_SCREENING): Candidate {
		$candidate = new Candidate();
		$candidate->setId(5);
		$candidate->setOpeningId(3);
		$candidate->setDisplayName('Ada');
		$candidate->setStage($stage);
		$candidate->setRejectionReason($stage === Candidate::STAGE_REJECTED ? 'not_qualified' : null);
		return $candidate;
	}

	private function manager(string $uid): TeamMember {
		$member = new TeamMember();
		$member->setUid($uid);
		$member->setRole(TeamMember::ROLE_MANAGER);
		return $member;
	}

	public function testMalformedTokensNeverReachTheDatabase(): void {
		$this->candidateMapper->expects($this->never())->method('findByPortalToken');
		$this->assertNull($this->service->findByToken('short'));
		$this->assertNull($this->service->findByToken(str_repeat('a', 40) . '../'));
	}

	public function testAnonymizedAndUnknownTokensResolveToNothing(): void {
		$anonymized = $this->candidate();
		$anonymized->setAnonymizedAt(new \DateTime());
		$this->candidateMapper->method('findByPortalToken')->willReturnOnConsecutiveCalls(null, $anonymized, $this->candidate());
		$token = str_repeat('a', 48);
		$this->assertNull($this->service->findByToken($token));
		$this->assertNull($this->service->findByToken($token));
		$this->assertSame(5, $this->service->findByToken($token)?->getId());
	}

	public function testPortalUrlCreatesATokenLazilyOnce(): void {
		$candidate = $this->candidate();
		$this->candidateMapper->expects($this->once())->method('update');
		$url = $this->service->portalUrl($candidate);
		$this->assertSame('https://cloud.example/recruiting.portal.show/' . str_repeat('p', 48), $url);
		$this->assertSame($url, $this->service->portalUrl($candidate));
	}

	public function testViewShowsTheCoarseStatusAndUpcomingInterviewsOnly(): void {
		$confirmed = $this->interview(1, Interview::STATUS_CONFIRMED, '2026-11-12T10:00:00Z');
		$past = $this->interview(2, Interview::STATUS_CONFIRMED, '2026-11-01T10:00:00Z');
		$proposed = $this->interview(3, Interview::STATUS_PROPOSED, null);
		$cancelled = $this->interview(4, Interview::STATUS_CANCELLED, '2026-11-13T10:00:00Z');
		$this->interviewMapper->method('findForCandidate')->willReturn([$cancelled, $proposed, $past, $confirmed]);
		$this->documentMapper->method('countFor')->willReturn([5 => 2]);

		$view = $this->service->view($this->candidate(Candidate::STAGE_INTERVIEW));
		$this->assertSame(CandidateStatus::INTERVIEW, $view['status']);
		$this->assertSame(['Interview 1', 'Interview 3'], array_column($view['interviews'], 'title'));
		$this->assertTrue($view['interviews'][0]['confirmed']);
		$this->assertSame('https://cloud.example/recruiting.public.showInterview/token1', $view['interviews'][0]['url']);
		$this->assertTrue($view['canWithdraw']);
		$this->assertTrue($view['canUpload']);
		// Nothing internal leaks into the page data: no stage name, no team
		$this->assertArrayNotHasKey('stage', $view);
		$this->assertStringNotContainsString('boss', (string)json_encode($view));
	}

	public function testRejectedCandidatesSeeClosedWithoutReasonOrInterviews(): void {
		$this->interviewMapper->expects($this->never())->method('findForCandidate');
		$view = $this->service->view($this->candidate(Candidate::STAGE_REJECTED));
		$this->assertSame(CandidateStatus::CLOSED, $view['status']);
		$this->assertSame([], $view['interviews']);
		$this->assertFalse($view['canWithdraw']);
		$this->assertFalse($view['canUpload']);
		$this->assertStringNotContainsString('not_qualified', (string)json_encode($view));
	}

	private function interview(int $id, string $status, ?string $start): Interview {
		$interview = new Interview();
		$interview->setId($id);
		$interview->setTitle('Interview ' . $id);
		$interview->setStatus($status);
		$interview->setPublicToken('token' . $id);
		$interview->setCreatedBy('boss');
		if ($start !== null) {
			$interview->setStartAt(new \DateTime($start));
			$interview->setEndAt((new \DateTime($start))->modify('+1 hour'));
		}
		return $interview;
	}

	public function testWithdrawMovesThroughStageServiceAndTellsTheManagers(): void {
		$candidate = $this->candidate();
		$this->teamMapper->method('findForOpening')->willReturn([$this->manager('boss')]);
		$this->stages->expects($this->once())->method('apply')
			->with($candidate, Candidate::STAGE_WITHDRAWN, null, ['byCandidate' => true]);
		$this->notifications->expects($this->once())->method('notify')
			->with(['boss'], NotificationService::SUBJECT_CANDIDATE_WITHDREW, $candidate, null, $this->anything());
		$this->service->withdraw($candidate);
	}

	public function testClosedApplicationsCannotBeWithdrawn(): void {
		$this->stages->expects($this->never())->method('apply');
		$this->expectException(ValidationException::class);
		$this->service->withdraw($this->candidate(Candidate::STAGE_HIRED));
	}

	public function testDeletionRequestIsRecordedOnceAndRoutedToHr(): void {
		$candidate = $this->candidate();
		$this->candidateMapper->expects($this->once())->method('update');
		$this->timeline->expects($this->once())->method('record')->with(5, TimelineEvent::TYPE_DELETION_REQUESTED, null);
		$this->notifications->expects($this->once())->method('notify')
			->with(['hr-anna'], NotificationService::SUBJECT_DELETION_REQUESTED, $candidate, null);

		$this->service->requestDeletion($candidate);
		$this->assertNotNull($candidate->getDeletionRequestedAt());
		$this->service->requestDeletion($candidate);
	}

	public function testUploadsAreCappedPerCandidate(): void {
		$this->documentMapper->method('countFor')->willReturn([5 => PortalService::MAX_DOCUMENTS]);
		$this->documents->expects($this->never())->method('add');
		$this->expectException(ValidationException::class);
		$this->service->addDocument($this->candidate(), 'cv.pdf', 'application/pdf', '%PDF');
	}

	public function testUploadStoresRecordsAndNotifiesHrWhenNoManagers(): void {
		$candidate = $this->candidate();
		$this->documentMapper->method('countFor')->willReturn([5 => 1]);
		$this->teamMapper->method('findForOpening')->willReturn([]);
		$document = new Document();
		$document->setName('cv.pdf');
		$this->documents->expects($this->once())->method('add')->with($candidate, 'cv.pdf', 'application/pdf', '%PDF', null)->willReturn($document);
		$this->timeline->expects($this->once())->method('record')
			->with(5, TimelineEvent::TYPE_DOCUMENT_ADDED, null, ['name' => 'cv.pdf', 'byCandidate' => true]);
		$this->notifications->expects($this->once())->method('notify')
			->with(['hr-anna'], NotificationService::SUBJECT_CANDIDATE_UPLOADED, $candidate, null);
		$this->service->addDocument($candidate, 'cv.pdf', 'application/pdf', '%PDF');
	}

	public function testClosedApplicationsTakeNoUploads(): void {
		$this->documents->expects($this->never())->method('add');
		$this->expectException(ValidationException::class);
		$this->service->addDocument($this->candidate(Candidate::STAGE_WITHDRAWN), 'cv.pdf', 'application/pdf', '%PDF');
	}
}
