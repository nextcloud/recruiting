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
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\Offer;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\Vote;
use OCA\Recruiting\Db\VoteMapper;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\TodayService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Today overview never shows more than the candidate detail would:
 * every entry is checked on its own, and recruiter-only parts stay empty
 * for everyone else.
 */
class TodayServiceTest extends TestCase {
	private PermissionService&MockObject $permissions;
	private CandidateService&MockObject $candidates;
	private CandidateMapper&MockObject $candidateMapper;
	private TeamMemberMapper&MockObject $teamMapper;
	private AssignmentMapper&MockObject $assignmentMapper;
	private VoteMapper&MockObject $voteMapper;
	private InterviewMapper&MockObject $interviewMapper;
	private OfferMapper&MockObject $offerMapper;
	private ConfigService&MockObject $config;
	/** @var array<int, Candidate> */
	private array $all = [];
	/** @var int[] */
	private array $visible = [];
	private TodayService $service;

	protected function setUp(): void {
		$this->permissions = $this->createMock(PermissionService::class);
		$this->permissions->method('canViewCandidate')->willReturnCallback(
			fn (string $uid, Candidate $c) => in_array($c->getId(), $this->visible, true),
		);
		$this->candidates = $this->createMock(CandidateService::class);
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->candidateMapper->method('findByIds')->willReturnCallback(
			fn (array $ids) => array_values(array_filter($this->all, static fn (Candidate $c) => in_array($c->getId(), $ids, true))),
		);
		$this->teamMapper = $this->createMock(TeamMemberMapper::class);
		$this->assignmentMapper = $this->createMock(AssignmentMapper::class);
		$this->voteMapper = $this->createMock(VoteMapper::class);
		$this->interviewMapper = $this->createMock(InterviewMapper::class);
		$this->offerMapper = $this->createMock(OfferMapper::class);
		$this->config = $this->createMock(ConfigService::class);
		$openingMapper = $this->createMock(OpeningMapper::class);
		$openingMapper->method('findTitle')->willReturn('Backend Engineer');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-11-11T08:00:00Z'));

		$this->service = new TodayService(
			$this->permissions,
			$this->candidates,
			$this->candidateMapper,
			$openingMapper,
			$this->teamMapper,
			$this->assignmentMapper,
			$this->voteMapper,
			$this->interviewMapper,
			$this->offerMapper,
			$this->config,
			$this->createMock(IUserManager::class),
			$time,
		);
	}

	private function candidate(int $id, string $stage = Candidate::STAGE_SCREENING): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setOpeningId(1);
		$candidate->setDisplayName('Candidate ' . $id);
		$candidate->setStage($stage);
		$candidate->setEmail('c' . $id . '@example.org');
		$this->all[$id] = $candidate;
		return $candidate;
	}

	private function interview(int $id, int $candidateId, string $status, ?string $start): Interview {
		$interview = new Interview();
		$interview->setId($id);
		$interview->setCandidateId($candidateId);
		$interview->setTitle('Interview ' . $id);
		$interview->setStatus($status);
		if ($start !== null) {
			$interview->setStartAt(new \DateTime($start));
			$interview->setEndAt((new \DateTime($start))->modify('+1 hour'));
		}
		return $interview;
	}

	public function testVotesOwedAreAssignedActiveVisibleAndNotYetVoted(): void {
		$this->candidate(1);
		$this->candidate(2);
		$this->candidate(3, Candidate::STAGE_REJECTED);
		$this->candidate(4);
		$this->visible = [1, 2, 3];
		$this->assignmentMapper->method('findCandidateIdsForUser')->willReturn([1, 2, 3, 4]);
		$vote = new Vote();
		$vote->setCandidateId(2);
		$this->voteMapper->method('findForUser')->willReturn([$vote]);

		$today = $this->service->forUser('screener');

		$this->assertSame([1], array_column($today['votesOwed'], 'id'));
		$this->assertSame(1, $today['votesOwedCount']);
		$this->assertArrayNotHasKey('email', $today['votesOwed'][0]);
	}

	public function testOnlyConfirmedVisibleInterviewsOfTheComingWeekAreListed(): void {
		foreach ([1, 2, 3, 4, 5] as $id) {
			$this->candidate($id, Candidate::STAGE_INTERVIEW);
		}
		$this->visible = [1, 2, 3, 4];
		$this->interviewMapper->method('findUpcomingForUser')->willReturn([
			$this->interview(10, 1, Interview::STATUS_CONFIRMED, '2026-11-11T14:00:00Z'),
			$this->interview(11, 2, Interview::STATUS_CONFIRMED, '2026-11-25T14:00:00Z'),
			$this->interview(12, 3, Interview::STATUS_PROPOSED, null),
			$this->interview(13, 4, Interview::STATUS_CONFIRMED, '2026-11-16T09:00:00Z'),
			$this->interview(14, 5, Interview::STATUS_CONFIRMED, '2026-11-12T09:00:00Z'),
		]);

		$today = $this->service->forUser('interviewer');

		$this->assertSame([10, 13], array_column($today['interviews'], 'id'));
		$this->assertSame('Candidate 1', $today['interviews'][0]['candidate']['displayName']);
	}

	public function testApprovalsNameTheJobButNeverTheTerms(): void {
		$this->candidate(1, Candidate::STAGE_OFFER);
		$this->candidate(2, Candidate::STAGE_OFFER);
		$this->visible = [1];
		$offers = [];
		foreach ([1, 2] as $candidateId) {
			$offer = new Offer();
			$offer->setId(20 + $candidateId);
			$offer->setCandidateId($candidateId);
			$offer->setJobTitle('Engineer');
			$offer->setSalaryAmount('99000');
			$offer->setCreatedBy('boss');
			$offers[] = $offer;
		}
		$this->offerMapper->method('findPendingForApprover')->willReturn($offers);

		$today = $this->service->forUser('approver');

		$this->assertCount(1, $today['approvals']);
		$this->assertSame(21, $today['approvals'][0]['offerId']);
		$this->assertStringNotContainsString('99000', (string)json_encode($today['approvals']));
	}

	public function testStuckCandidatesComeFromTheBoardLogicAndAreCut(): void {
		$stuck = array_map(static fn (int $id) => ['id' => $id], range(1, 25));
		$this->candidates->expects($this->once())->method('stuckCandidates')->with('manager')->willReturn($stuck);

		$today = $this->service->forUser('manager');

		$this->assertCount(TodayService::LIST_LIMIT, $today['stuck']);
		$this->assertSame(25, $today['stuckCount']);
	}

	public function testTriageAndMailboxAreForRecruitersOnly(): void {
		$this->permissions->method('isRecruiter')->willReturnMap([['hr', true], ['manager', false]]);
		$this->candidateMapper->method('countTriage')->willReturn(4);
		$this->config->method('isImapEnabled')->willReturn(true);
		$this->config->method('getIngestionStatus')->willReturn(['ranAt' => '2026-11-11T07:55:00+00:00', 'error' => null]);

		$hr = $this->service->forUser('hr');
		$manager = $this->service->forUser('manager');

		$this->assertSame(4, $hr['triageCount']);
		$this->assertTrue($hr['mailbox']['enabled']);
		$this->assertNull($manager['triageCount']);
		$this->assertNull($manager['mailbox']);
	}

	public function testOpeningCountDrivesTheEmptyState(): void {
		$this->permissions->method('isRecruiter')->willReturn(false);
		$member = new TeamMember();
		$member->setOpeningId(1);
		$this->teamMapper->method('findForUser')->willReturn([$member]);

		$this->assertSame(1, $this->service->forUser('manager')['openingCount']);
	}

	public function testOnlyPeopleWithAPartInRecruitingHaveARole(): void {
		$this->permissions->method('isRecruiter')->willReturnMap([['hr', true], ['bob', false], ['screener', false]]);
		$this->teamMapper->method('findForUser')->willReturn([]);
		$this->assignmentMapper->method('findCandidateIdsForUser')->willReturnMap([['bob', []], ['screener', [5]]]);
		$this->offerMapper->method('findPendingForApprover')->willReturn([]);

		$this->assertTrue($this->service->hasRecruitingRole('hr'));
		$this->assertTrue($this->service->hasRecruitingRole('screener'));
		$this->assertFalse($this->service->hasRecruitingRole('bob'));
		$this->assertFalse($this->service->hasRecruitingRole(''));
	}
}
