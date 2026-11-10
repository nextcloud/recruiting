<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Service\ActivityPurgeService;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\PermissionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The GDPR hard delete: one path for "delete on request" (permission
 * checked first) and for the retention automation (no user).
 */
class CandidatePurgeTest extends TestCase {
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

	private function candidate(): Candidate {
		$candidate = new Candidate();
		$candidate->setId(5);
		$candidate->setDisplayName('Jane Doe');
		return $candidate;
	}

	public function testPurgeRemovesCalendarTracesAndActivity(): void {
		$candidate = $this->candidate();
		$this->mock(InterviewService::class)->expects($this->once())->method('scrubForCandidate')->with($candidate, false);
		$this->mock(InterviewService::class)->expects($this->once())->method('deleteAllFor')->with(5);
		$this->mock(ActivityPurgeService::class)->expects($this->once())->method('purgeForCandidate')->with(5);
		$this->mock(CandidateMapper::class)->expects($this->once())->method('delete')->with($candidate);

		$this->service()->purge($candidate);
	}

	public function testDestroyChecksThePermissionBeforeDeletingAnything(): void {
		$this->mock(PermissionService::class)->method('assertRecruiter')
			->willThrowException(new NotPermittedException('Recruiter access required'));
		$this->mock(CandidateMapper::class)->expects($this->never())->method('delete');
		$this->mock(InterviewService::class)->expects($this->never())->method('scrubForCandidate');

		$this->expectException(NotPermittedException::class);
		$this->service()->destroy('intern', 5);
	}

	public function testDestroyDeletesForRecruiters(): void {
		$candidate = $this->candidate();
		$this->mock(CandidateMapper::class)->method('find')->with(5)->willReturn($candidate);
		$this->mock(CandidateMapper::class)->expects($this->once())->method('delete')->with($candidate);

		$this->service()->destroy('hr', 5);
	}
}
