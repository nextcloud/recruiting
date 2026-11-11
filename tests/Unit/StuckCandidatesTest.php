<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\LanguageService;
use OCA\Recruiting\Service\PermissionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The "stuck" list of the Today page (the board's lens across openings)
 * and the candidate language on manual entry and edit.
 */
class StuckCandidatesTest extends TestCase {
	/** @var array<class-string, MockObject> */
	private array $mocks = [];

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T&MockObject
	 */
	private function mock(string $class): MockObject {
		return $this->mocks[$class] ??= $this->createMock($class);
	}

	private function service(): CandidateService {
		$args = [];
		foreach ((new \ReflectionClass(CandidateService::class))->getConstructor()->getParameters() as $parameter) {
			/** @var class-string $type */
			$type = (string)$parameter->getType();
			$args[] = $this->mock($type);
		}
		return new CandidateService(...$args);
	}

	protected function setUp(): void {
		$this->mock(ITimeFactory::class)->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-11-11T12:00:00Z'));
		$this->mock(OpeningMapper::class)->method('findAll')->willReturn([
			$this->opening(1, Opening::STATUS_OPEN),
			$this->opening(2, Opening::STATUS_OPEN),
			$this->opening(3, Opening::STATUS_CLOSED),
		]);
	}

	private function opening(int $id, string $status): Opening {
		$opening = new Opening();
		$opening->setId($id);
		$opening->setTitle('Opening ' . $id);
		$opening->setStatus($status);
		return $opening;
	}

	private function candidate(int $id, int $openingId, string $stage, string $since): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setOpeningId($openingId);
		$candidate->setDisplayName('Candidate ' . $id);
		$candidate->setStage($stage);
		$candidate->setStageChangedAt(new \DateTime($since));
		return $candidate;
	}

	private function membership(int $openingId, string $role): TeamMember {
		$member = new TeamMember();
		$member->setOpeningId($openingId);
		$member->setRole($role);
		return $member;
	}

	public function testManagersSeeOnlyTheirOpenOpenings(): void {
		$this->mock(PermissionService::class)->method('isRecruiter')->willReturn(false);
		$this->mock(PermissionService::class)->method('canManageCandidate')->willReturn(true);
		$this->mock(TeamMemberMapper::class)->method('findForUser')->willReturn([
			$this->membership(1, TeamMember::ROLE_MANAGER),
			$this->membership(2, TeamMember::ROLE_INTERVIEWER),
			$this->membership(3, TeamMember::ROLE_MANAGER),
		]);
		$this->mock(CandidateMapper::class)->expects($this->once())->method('findActiveInOpenings')
			->with([1])
			->willReturn([$this->candidate(5, 1, Candidate::STAGE_NEW, '2026-11-01T12:00:00Z')]);

		$stuck = $this->service()->stuckCandidates('manager');

		$this->assertSame([[
			'id' => 5,
			'displayName' => 'Candidate 5',
			'openingId' => 1,
			'openingTitle' => 'Opening 1',
			'stage' => Candidate::STAGE_NEW,
			'attention' => ['waiting'],
		]], $stuck);
	}

	public function testPeopleWhoManageNothingGetNothing(): void {
		$this->mock(PermissionService::class)->method('isRecruiter')->willReturn(false);
		$this->mock(TeamMemberMapper::class)->method('findForUser')->willReturn([$this->membership(1, TeamMember::ROLE_OBSERVER)]);
		$this->mock(CandidateMapper::class)->expects($this->never())->method('findActiveInOpenings');

		$this->assertSame([], $this->service()->stuckCandidates('observer'));
	}

	public function testRecruitersSeeAllOpenOpeningsButOnlyStuckCandidates(): void {
		$this->mock(PermissionService::class)->method('isRecruiter')->willReturn(true);
		$this->mock(PermissionService::class)->method('canManageCandidate')->willReturn(true);
		$this->mock(CandidateMapper::class)->expects($this->once())->method('findActiveInOpenings')
			->with([1, 2])
			->willReturn([
				$this->candidate(5, 1, Candidate::STAGE_SCREENING, '2026-11-01T12:00:00Z'),
				$this->candidate(6, 2, Candidate::STAGE_SCREENING, '2026-11-01T12:00:00Z'),
				$this->candidate(7, 2, Candidate::STAGE_NEW, '2026-11-10T12:00:00Z'),
			]);
		$this->mock(\OCA\Recruiting\Db\VoteMapper::class)->method('tallyFor')->willReturn([6 => ['yes' => 1]]);

		$stuck = $this->service()->stuckCandidates('hr');

		$this->assertSame([5], array_column($stuck, 'id'));
		$this->assertSame(['no_votes'], $stuck[0]['attention']);
	}

	public function testEveryCandidateIsPermissionCheckedOnItsOwn(): void {
		$this->mock(PermissionService::class)->method('isRecruiter')->willReturn(true);
		$this->mock(PermissionService::class)->method('canManageCandidate')->willReturn(false);
		$this->mock(CandidateMapper::class)->method('findActiveInOpenings')
			->willReturn([$this->candidate(5, 1, Candidate::STAGE_NEW, '2026-10-01T12:00:00Z')]);

		$this->assertSame([], $this->service()->stuckCandidates('hr'));
	}

	public function testManualEntryStoresTheLanguage(): void {
		$this->mock(LanguageService::class)->method('parse')->with('de')->willReturn('de');
		$this->mock(CandidateMapper::class)->expects($this->once())->method('insert')
			->with($this->callback(static fn (Candidate $c) => $c->getLanguage() === 'de'))
			->willReturnCallback(static function (Candidate $c) {
				$c->setId(9);
				return $c;
			});
		$this->mock(CandidateMapper::class)->method('find')->willReturnCallback(fn () => $this->candidate(9, 1, Candidate::STAGE_NEW, '2026-11-11T12:00:00Z'));

		$this->service()->createManual('hr', ['openingId' => 1, 'displayName' => 'Ada', 'language' => 'de']);
	}

	public function testEditingValidatesTheLanguageAfterThePermissionCheck(): void {
		$candidate = $this->candidate(9, 1, Candidate::STAGE_NEW, '2026-11-11T12:00:00Z');
		$this->mock(CandidateMapper::class)->method('find')->willReturn($candidate);
		$this->mock(PermissionService::class)->expects($this->once())->method('assertCanManageCandidate');
		$this->mock(LanguageService::class)->method('parse')->willThrowException(new ValidationException('Unknown language'));
		$this->mock(CandidateMapper::class)->expects($this->never())->method('update');

		$this->expectException(ValidationException::class);
		$this->service()->update('hr', 9, ['language' => 'klingon']);
	}

	public function testEditingCanClearTheLanguage(): void {
		$candidate = $this->candidate(9, 1, Candidate::STAGE_NEW, '2026-11-11T12:00:00Z');
		$candidate->setLanguage('de');
		$this->mock(CandidateMapper::class)->method('find')->willReturn($candidate);
		$this->mock(LanguageService::class)->method('parse')->with('')->willReturn(null);
		$this->mock(CandidateMapper::class)->expects($this->once())->method('update');

		$this->service()->update('hr', 9, ['language' => '']);

		$this->assertNull($candidate->getLanguage());
	}
}
