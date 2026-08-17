<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Assignment;
use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\PermissionService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase {
	private IGroupManager $groupManager;
	private ConfigService $config;
	private TeamMemberMapper $teamMapper;
	private AssignmentMapper $assignmentMapper;
	private InterviewAttendeeMapper $attendeeMapper;
	private OfferMapper $offerMapper;
	private PermissionService $service;

	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->teamMapper = $this->createMock(TeamMemberMapper::class);
		$this->assignmentMapper = $this->createMock(AssignmentMapper::class);
		$this->attendeeMapper = $this->createMock(InterviewAttendeeMapper::class);
		$this->offerMapper = $this->createMock(OfferMapper::class);

		$this->config->method('getHrGroup')->willReturn('recruiting');
		$this->service = new PermissionService(
			$this->groupManager,
			$this->config,
			$this->teamMapper,
			$this->assignmentMapper,
			$this->attendeeMapper,
			$this->offerMapper,
		);
	}

	private function setRecruiter(string $uid, bool $is): void {
		$this->groupManager->method('isInGroup')
			->willReturnCallback(fn (string $u, string $g) => $u === $uid && $g === 'recruiting' && $is);
	}

	private function setRole(string $uid, int $openingId, ?string $role): void {
		$member = null;
		if ($role !== null) {
			$member = new TeamMember();
			$member->setOpeningId($openingId);
			$member->setUid($uid);
			$member->setRole($role);
		}
		$this->teamMapper->method('findMembership')
			->willReturnCallback(fn (int $o, string $u) => ($o === $openingId && $u === $uid) ? $member : null);
	}

	private function candidate(?int $openingId, int $id = 7): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setOpeningId($openingId);
		return $candidate;
	}

	public function testRecruiterHasFullAccess(): void {
		$this->setRecruiter('hr1', true);
		$candidate = $this->candidate(3);

		$this->assertTrue($this->service->isRecruiter('hr1'));
		$this->assertSame('recruiter', $this->service->roleForOpening('hr1', 3));
		$this->assertTrue($this->service->canManageOpening('hr1', 3));
		$this->assertTrue($this->service->canViewCandidate('hr1', $candidate));
		$this->assertTrue($this->service->canManageCandidate('hr1', $candidate));
		$this->assertTrue($this->service->canVote('hr1', $candidate));
		$this->assertTrue($this->service->canComment('hr1', $candidate));
	}

	public function testManagerCanManageTheirOpening(): void {
		$this->setRecruiter('mgr', false);
		$this->setRole('mgr', 3, TeamMember::ROLE_MANAGER);
		$candidate = $this->candidate(3);

		$this->assertTrue($this->service->canManageOpening('mgr', 3));
		$this->assertTrue($this->service->canViewCandidate('mgr', $candidate));
		$this->assertTrue($this->service->canManageCandidate('mgr', $candidate));
		$this->assertTrue($this->service->canVote('mgr', $candidate));
	}

	public function testInterviewerOnlySeesAssignedCandidates(): void {
		$this->setRecruiter('dev1', false);
		$this->setRole('dev1', 3, TeamMember::ROLE_INTERVIEWER);
		$assigned = $this->candidate(3, 7);
		$other = $this->candidate(3, 8);

		$assignment = new Assignment();
		$this->assignmentMapper->method('findAssignment')
			->willReturnCallback(fn (int $c, string $u) => ($c === 7 && $u === 'dev1') ? $assignment : null);
		$this->attendeeMapper->method('findCandidateIdsForUser')->willReturn([]);

		$this->assertTrue($this->service->canViewCandidate('dev1', $assigned));
		$this->assertFalse($this->service->canViewCandidate('dev1', $other));
		$this->assertFalse($this->service->canManageOpening('dev1', 3));
		$this->assertFalse($this->service->canManageCandidate('dev1', $assigned));
		$this->assertTrue($this->service->canVote('dev1', $assigned));
		$this->assertFalse($this->service->canVote('dev1', $other));
	}

	public function testObserverIsReadOnly(): void {
		$this->setRecruiter('boss', false);
		$this->setRole('boss', 3, TeamMember::ROLE_OBSERVER);
		$candidate = $this->candidate(3);
		$this->assignmentMapper->method('findAssignment')->willReturn(null);

		$this->assertTrue($this->service->canViewCandidate('boss', $candidate));
		$this->assertFalse($this->service->canManageCandidate('boss', $candidate));
		$this->assertFalse($this->service->canVote('boss', $candidate));
		$this->assertFalse($this->service->canComment('boss', $candidate));
	}

	public function testOutsiderHasNoAccess(): void {
		$this->setRecruiter('nobody', false);
		$this->setRole('nobody', 3, null);
		$candidate = $this->candidate(3);

		$this->assertFalse($this->service->canViewOpening('nobody', 3));
		$this->assertFalse($this->service->canViewCandidate('nobody', $candidate));
		$this->assertFalse($this->service->canVote('nobody', $candidate));
	}

	public function testTriageIsRecruiterOnly(): void {
		$this->setRecruiter('mgr', false);
		$triaged = $this->candidate(null);

		$this->assertFalse($this->service->canViewCandidate('mgr', $triaged));
		$this->assertFalse($this->service->canManageCandidate('mgr', $triaged));
	}

	public function testOfferApproverGetsScopedAccess(): void {
		$this->setRecruiter('head', false);
		$this->setRole('head', 3, null);
		$candidate = $this->candidate(3);
		$this->offerMapper->method('isApproverForCandidate')
			->willReturnCallback(fn (int $c, string $u) => $c === 7 && $u === 'head');

		$this->assertTrue($this->service->canViewCandidate('head', $candidate));
		$this->assertTrue($this->service->canSeeOffers('head', $candidate));
		$this->assertFalse($this->service->canManageCandidate('head', $candidate));
		$this->assertFalse($this->service->canVote('head', $candidate));
	}

	/**
	 * Regression: the approver fallback used to apply only to users without
	 * any role, so an interviewer picked as approver was locked out of the
	 * very candidate they had to decide on.
	 */
	public function testInterviewerWhoIsApproverCanSeeTheCandidate(): void {
		$this->setRecruiter('dev1', false);
		$this->setRole('dev1', 3, TeamMember::ROLE_INTERVIEWER);
		$candidate = $this->candidate(3);
		$this->assignmentMapper->method('findAssignment')->willReturn(null);
		$this->attendeeMapper->method('findCandidateIdsForUser')->willReturn([]);
		$this->offerMapper->method('isApproverForCandidate')->willReturn(true);

		$this->assertTrue($this->service->canViewCandidate('dev1', $candidate));
		$this->assertTrue($this->service->canSeeOffers('dev1', $candidate));
		// … but approving does not make them a manager
		$this->assertFalse($this->service->canManageCandidate('dev1', $candidate));
	}

	public function testInterviewerNeverSeesOffers(): void {
		$this->setRecruiter('dev1', false);
		$this->setRole('dev1', 3, TeamMember::ROLE_INTERVIEWER);
		$candidate = $this->candidate(3);
		$this->offerMapper->method('isApproverForCandidate')->willReturn(false);

		$this->assertFalse($this->service->canSeeOffers('dev1', $candidate));
	}

	public function testAssertThrows(): void {
		$this->setRecruiter('nobody', false);
		$this->expectException(NotPermittedException::class);
		$this->service->assertRecruiter('nobody');
	}
}
