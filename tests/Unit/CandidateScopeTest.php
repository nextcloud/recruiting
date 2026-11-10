<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\PermissionService;
use OCP\IGroupManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class CandidateScopeTest extends TestCase {
	private function service(bool $recruiter, array $memberships): PermissionService {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn($recruiter);
		$config = $this->createMock(ConfigService::class);
		$config->method('getHrGroup')->willReturn('recruiting');
		$teamMapper = $this->createMock(TeamMemberMapper::class);
		$teamMapper->method('findForUser')->willReturn(array_map(static function (array $m) {
			$member = new TeamMember();
			$member->setOpeningId($m[0]);
			$member->setUid('alice');
			$member->setRole($m[1]);
			return $member;
		}, $memberships));
		return new PermissionService(
			$groupManager,
			$config,
			$teamMapper,
			$this->createMock(AssignmentMapper::class),
			$this->createMock(InterviewAttendeeMapper::class),
			$this->createMock(OfferMapper::class),
			$this->createMock(IL10N::class),
		);
	}

	public function testRecruitersSeeEverything(): void {
		$scope = $this->service(true, [[3, TeamMember::ROLE_INTERVIEWER]])->candidateScope('hr');
		$this->assertTrue($scope->everything);
		$this->assertSame('hr', $scope->uid);
	}

	public function testRolesSplitIntoFullAndAssignedOpenings(): void {
		$scope = $this->service(false, [
			[1, TeamMember::ROLE_MANAGER],
			[2, TeamMember::ROLE_OBSERVER],
			[3, TeamMember::ROLE_INTERVIEWER],
		])->candidateScope('alice');

		$this->assertFalse($scope->everything);
		$this->assertSame([1, 2], $scope->fullOpeningIds);
		$this->assertSame([3], $scope->assignedOpeningIds);
		$this->assertSame([1, 2, 3], $scope->visibleOpeningIds());
	}

	public function testNoRolesMeansNoOpenings(): void {
		$scope = $this->service(false, [])->candidateScope('bob');
		$this->assertFalse($scope->everything);
		$this->assertSame([], $scope->visibleOpeningIds());
	}
}
