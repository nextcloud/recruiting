<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\OpeningService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\TalkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Deleting openings (recruiters only, never with candidates) and the
 * board's change marker.
 */
class OpeningServiceTest extends TestCase {
	private OpeningMapper&MockObject $openingMapper;
	private TeamMemberMapper&MockObject $teamMapper;
	private CandidateMapper&MockObject $candidateMapper;
	private PermissionService&MockObject $permissions;
	private TalkService&MockObject $talk;
	private OpeningService $service;

	protected function setUp(): void {
		$this->openingMapper = $this->createMock(OpeningMapper::class);
		$this->teamMapper = $this->createMock(TeamMemberMapper::class);
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->talk = $this->createMock(TalkService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		$this->service = new OpeningService(
			$this->openingMapper,
			$this->teamMapper,
			$this->candidateMapper,
			$this->permissions,
			$this->talk,
			$this->createMock(IUserManager::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IURLGenerator::class),
			$l10n,
		);
	}

	private function opening(?string $talkToken = null): Opening {
		$opening = new Opening();
		$opening->setId(7);
		$opening->setTitle('Backend Engineer');
		$opening->setTalkToken($talkToken);
		return $opening;
	}

	public function testOnlyRecruitersMayDelete(): void {
		$this->permissions->method('assertRecruiter')->willThrowException(new NotPermittedException('Recruiter access required'));
		$this->openingMapper->expects($this->never())->method('find');
		$this->openingMapper->expects($this->never())->method('delete');
		$this->teamMapper->expects($this->never())->method('deleteForOpening');

		$this->expectException(NotPermittedException::class);
		$this->service->destroy('manager', 7);
	}

	public function testOpeningsWithCandidatesAreNeverDeleted(): void {
		$this->openingMapper->method('find')->willReturn($this->opening('room1'));
		$this->candidateMapper->expects($this->once())->method('countForOpening')->with(7)->willReturn(1);
		$this->openingMapper->expects($this->never())->method('delete');
		$this->teamMapper->expects($this->never())->method('deleteForOpening');
		$this->talk->expects($this->never())->method('deleteRoom');

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Close it instead');
		$this->service->destroy('hr', 7);
	}

	public function testDeleteRemovesTeamTalkRoomAndOpening(): void {
		$opening = $this->opening('room1');
		$this->openingMapper->method('find')->willReturn($opening);
		$this->candidateMapper->method('countForOpening')->willReturn(0);
		$this->talk->expects($this->once())->method('deleteRoom')->with('room1');
		$this->teamMapper->expects($this->once())->method('deleteForOpening')->with(7);
		$this->openingMapper->expects($this->once())->method('delete')->with($opening);
		$this->permissions->expects($this->once())->method('clearRoleCache');

		$this->service->destroy('hr', 7);
	}

	public function testDeleteWithoutTalkRoomLeavesTalkAlone(): void {
		$this->openingMapper->method('find')->willReturn($this->opening());
		$this->candidateMapper->method('countForOpening')->willReturn(0);
		$this->talk->expects($this->never())->method('deleteRoom');
		$this->openingMapper->expects($this->once())->method('delete');

		$this->service->destroy('hr', 7);
	}

	public function testRevisionComesFromTheCandidateMarker(): void {
		$this->permissions->expects($this->once())->method('assertCanViewOpening')->with('hr', 7);
		$this->candidateMapper->method('revisionForOpening')->with(7)->willReturn('3-12-2026-11-10 10:00:00-40');

		$this->assertSame(['revision' => '3-12-2026-11-10 10:00:00-40'], $this->service->revision('hr', 7));
	}

	public function testRevisionRequiresViewPermission(): void {
		$this->permissions->method('assertCanViewOpening')->willThrowException(new NotPermittedException('no'));
		$this->candidateMapper->expects($this->never())->method('revisionForOpening');

		$this->expectException(NotPermittedException::class);
		$this->service->revision('stranger', 7);
	}
}
