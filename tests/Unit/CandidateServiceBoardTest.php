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
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Db\VoteMapper;
use OCA\Recruiting\Exception\ConflictException;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\ActivityPurgeService;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\LanguageService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\OfferService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\PoolService;
use OCA\Recruiting\Service\PortalService;
use OCA\Recruiting\Service\StageService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\ICommentsManager;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The board endpoint (lazy terminal stages) and the stage move with an
 * expected current stage (conflict detection between two recruiters).
 */
class CandidateServiceBoardTest extends TestCase {
	private CandidateMapper&MockObject $candidateMapper;
	private AssignmentMapper&MockObject $assignmentMapper;
	private PermissionService&MockObject $permissions;
	private StageService&MockObject $stages;
	private MailService&MockObject $mail;
	private CandidateService $service;

	protected function setUp(): void {
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->assignmentMapper = $this->createMock(AssignmentMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->stages = $this->createMock(StageService::class);
		$this->mail = $this->createMock(MailService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-10T12:00:00+00:00'));

		$this->service = new CandidateService(
			$this->candidateMapper,
			$this->createMock(OpeningMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->assignmentMapper,
			$this->createMock(DocumentMapper::class),
			$this->createMock(VoteMapper::class),
			$this->createMock(InterviewMapper::class),
			$this->createMock(InterviewAttendeeMapper::class),
			$this->createMock(OfferMapper::class),
			$this->createMock(OfferService::class),
			$this->permissions,
			$this->createMock(DocumentService::class),
			$this->createMock(InterviewService::class),
			$this->mail,
			$this->createMock(PoolService::class),
			$this->stages,
			$this->createMock(TimelineService::class),
			$this->createMock(TimelineEventMapper::class),
			$this->createMock(NotificationService::class),
			$this->createMock(ActivityPublisher::class),
			$this->createMock(ActivityPurgeService::class),
			$this->createMock(ICommentsManager::class),
			$this->createMock(IUserManager::class),
			$time,
			$l10n,
			$this->createMock(PortalService::class),
			$this->createMock(LanguageService::class),
		);
	}

	private function candidate(int $id, string $stage, int $openingId = 7): Candidate {
		$c = new Candidate();
		$c->setId($id);
		$c->setOpeningId($openingId);
		$c->setDisplayName('Candidate ' . $id);
		$c->setEmail('c' . $id . '@example.org');
		$c->setStage($stage);
		$c->setCreatedAt(new \DateTime('2026-11-01T10:00:00+00:00'));
		$c->setStageChangedAt(new \DateTime('2026-11-01T10:00:00+00:00'));
		return $c;
	}

	public function testFirstBoardResponseLoadsOnlyActiveStages(): void {
		$this->permissions->method('roleForOpening')->willReturn(PermissionService::ROLE_RECRUITER);
		$requested = [];
		$this->candidateMapper->method('findForOpening')->willReturnCallback(
			function (int $openingId, ?string $stage, int $limit, int $offset) use (&$requested) {
				$requested[] = [$stage, $limit, $offset];
				return [$this->candidate(count($requested), (string)$stage)];
			},
		);

		$result = $this->service->listForOpening('hr', 7);

		$this->assertSame(Candidate::ACTIVE_STAGES, array_column($requested, 0));
		$this->assertSame([CandidateService::PAGE_SIZE], array_values(array_unique(array_column($requested, 1))));
		$this->assertSame(Candidate::ACTIVE_STAGES, array_column($result['cards'], 'stage'));
		$this->assertSame(Candidate::TERMINAL_STAGES, $result['deferred']);
		$this->assertFalse($result['complete']);
	}

	public function testTerminalStageLoadsOnDemandWithPaging(): void {
		$this->permissions->method('roleForOpening')->willReturn(TeamMember::ROLE_MANAGER);
		$this->candidateMapper->expects($this->once())
			->method('findForOpening')
			->with(7, Candidate::STAGE_REJECTED, CandidateService::PAGE_SIZE, 100)
			->willReturn([$this->candidate(3, Candidate::STAGE_REJECTED)]);

		$result = $this->service->listForOpening('manager', 7, Candidate::STAGE_REJECTED, 100);

		$this->assertSame([3], array_column($result['cards'], 'id'));
		$this->assertSame([], $result['deferred']);
		$this->assertFalse($result['complete']);
	}

	public function testInterviewersGetTheirWholeListIncludingTerminalCards(): void {
		$this->permissions->method('roleForOpening')->willReturn(TeamMember::ROLE_INTERVIEWER);
		$this->assignmentMapper->method('findCandidateIdsForUser')->willReturn([1, 2, 3]);
		$this->candidateMapper->expects($this->never())->method('findForOpening');
		$this->candidateMapper->method('findByIds')->willReturn([
			$this->candidate(1, Candidate::STAGE_SCREENING),
			$this->candidate(2, Candidate::STAGE_REJECTED),
			$this->candidate(3, Candidate::STAGE_NEW, 8), // other opening
		]);

		$result = $this->service->listForOpening('interviewer', 7);

		$this->assertSame([1, 2], array_column($result['cards'], 'id'));
		$this->assertTrue($result['complete']);
		$this->assertSame([], $result['deferred']);
	}

	public function testBoardRequiresViewPermission(): void {
		$this->permissions->method('assertCanViewOpening')->willThrowException(new NotPermittedException('no'));
		$this->candidateMapper->expects($this->never())->method('findForOpening');

		$this->expectException(NotPermittedException::class);
		$this->service->listForOpening('stranger', 7);
	}

	public function testStageMoveConflictsWhenSomeoneElseMovedTheCandidate(): void {
		$this->candidateMapper->method('find')->willReturn($this->candidate(5, Candidate::STAGE_INTERVIEW));
		$this->stages->expects($this->never())->method('apply');

		try {
			$this->service->setStage('hr', 5, Candidate::STAGE_OFFER, Candidate::STAGE_SCREENING);
			$this->fail('Expected a conflict');
		} catch (ConflictException $e) {
			$this->assertSame(Candidate::STAGE_INTERVIEW, $e->getData()['stage']);
			$this->assertSame('Candidate 5', $e->getData()['displayName']);
			$this->assertStringContainsString('Candidate 5', $e->getMessage());
		}
	}

	public function testStageMoveConflictIsCheckedAfterPermission(): void {
		$this->candidateMapper->method('find')->willReturn($this->candidate(5, Candidate::STAGE_INTERVIEW));
		$this->permissions->method('assertCanManageCandidate')->willThrowException(new NotPermittedException('no'));
		$this->stages->expects($this->never())->method('apply');

		// The current stage must not leak to someone who may not manage the candidate
		$this->expectException(NotPermittedException::class);
		$this->service->setStage('observer', 5, Candidate::STAGE_OFFER, Candidate::STAGE_SCREENING);
	}

	public function testStageMoveAppliesWhenExpectedStageMatches(): void {
		$candidate = $this->candidate(5, Candidate::STAGE_SCREENING);
		$this->candidateMapper->method('find')->willReturn($candidate);
		$this->permissions->method('canManageCandidate')->willReturn(true);
		$this->stages->expects($this->once())
			->method('apply')
			->with($candidate, Candidate::STAGE_INTERVIEW, 'hr', [], true);

		$this->service->setStage('hr', 5, Candidate::STAGE_INTERVIEW, Candidate::STAGE_SCREENING);
	}

	public function testStageMoveWithoutExpectedStageNeverConflicts(): void {
		$this->candidateMapper->method('find')->willReturn($this->candidate(5, Candidate::STAGE_OFFER));
		$this->permissions->method('canManageCandidate')->willReturn(true);
		$this->stages->expects($this->once())->method('apply');

		$this->service->setStage('hr', 5, Candidate::STAGE_INTERVIEW);
	}

	public function testRejectCandidateChecksPermissionBeforeMailing(): void {
		$this->permissions->method('assertCanManageCandidate')->willThrowException(new NotPermittedException('no'));
		$this->mail->expects($this->never())->method('sendToCandidate');
		$this->stages->expects($this->never())->method('apply');

		$this->expectException(NotPermittedException::class);
		$this->service->rejectCandidate('observer', $this->candidate(5, Candidate::STAGE_NEW), 'other', 'Subject', 'Body');
	}

	public function testRejectCandidateMailsThenMovesToRejected(): void {
		$candidate = $this->candidate(5, Candidate::STAGE_NEW);
		$order = [];
		$this->mail->expects($this->once())->method('sendToCandidate')
			->with($candidate, 'Subject', 'Body', 'hr')
			->willReturnCallback(function () use (&$order) {
				$order[] = 'mail';
			});
		$this->stages->expects($this->once())->method('apply')
			->with($candidate, Candidate::STAGE_REJECTED, 'hr', ['reason' => 'position_filled'], true)
			->willReturnCallback(function () use (&$order) {
				$order[] = 'stage';
				return true;
			});

		$this->service->rejectCandidate('hr', $candidate, 'position_filled', 'Subject', 'Body');

		$this->assertSame(['mail', 'stage'], $order);
		$this->assertSame('position_filled', $candidate->getRejectionReason());
	}
}
