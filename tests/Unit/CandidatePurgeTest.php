<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\VoteMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\ActivityPurgeService;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\TimelineService;
use OCP\Files\GenericFileException;
use OCP\IDBConnection;
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

	/**
	 * @param list<string> $order
	 */
	private function track(MockObject $mock, string $method, string $label, array &$order): void {
		$mock->method($method)->willReturnCallback(function () use (&$order, $label) {
			$order[] = $label;
			return null;
		});
	}

	public function testCalendarAndFilesGoBeforeTheRowsWhichGoInOneTransaction(): void {
		$candidate = $this->candidate();
		$order = [];
		$this->track($this->mock(InterviewService::class), 'scrubForCandidate', 'calendar', $order);
		$this->track($this->mock(DocumentService::class), 'deleteAllFor', 'files', $order);
		$this->track($this->mock(IDBConnection::class), 'beginTransaction', 'begin', $order);
		$this->mock(CandidateMapper::class)->method('delete')->willReturnCallback(function (Candidate $c) use (&$order) {
			$order[] = 'row';
			return $c;
		});
		$this->track($this->mock(IDBConnection::class), 'commit', 'commit', $order);
		$this->track($this->mock(NotificationService::class), 'purgeForCandidate', 'notifications', $order);
		$this->track($this->mock(ActivityPurgeService::class), 'purgeForCandidate', 'activity', $order);

		$this->service()->purge($candidate);

		$this->assertSame(['calendar', 'files', 'begin', 'row', 'commit', 'notifications', 'activity'], $order);
	}

	public function testAFailedFileDeleteKeepsEveryRowForARetry(): void {
		$this->mock(DocumentService::class)->method('deleteAllFor')->willThrowException(new GenericFileException('still there'));
		$this->mock(IDBConnection::class)->expects($this->never())->method('beginTransaction');
		$this->mock(VoteMapper::class)->expects($this->never())->method('deleteForCandidate');
		$this->mock(CandidateMapper::class)->expects($this->never())->method('delete');

		$this->expectException(GenericFileException::class);
		$this->service()->purge($this->candidate());
	}

	public function testAFailingRowDeleteRollsBackAndLeavesTheTracesAlone(): void {
		$this->mock(OfferMapper::class)->method('deleteForCandidate')->willThrowException(new \RuntimeException('deadlock'));
		$this->mock(IDBConnection::class)->expects($this->once())->method('rollBack');
		$this->mock(IDBConnection::class)->expects($this->never())->method('commit');
		$this->mock(CandidateMapper::class)->expects($this->never())->method('delete');
		$this->mock(ActivityPurgeService::class)->expects($this->never())->method('purgeForCandidate');

		$this->expectException(\RuntimeException::class);
		$this->service()->purge($this->candidate());
	}

	public function testAssigningToAnOpeningWritesRowAndTimelineTogether(): void {
		$candidate = $this->candidate();
		$candidate->setEmail('jane@example.com');
		$this->mock(CandidateMapper::class)->method('find')->willReturn($candidate);
		$opening = new Opening();
		$opening->setId(7);
		$opening->setTitle('Backend');
		$this->mock(OpeningMapper::class)->method('find')->willReturn($opening);
		$this->mock(TimelineService::class)->method('record')->willThrowException(new \RuntimeException('db down'));
		$this->mock(IDBConnection::class)->expects($this->once())->method('beginTransaction');
		$this->mock(IDBConnection::class)->expects($this->once())->method('rollBack');
		$this->mock(ActivityPublisher::class)->expects($this->never())->method('publish');
		$this->mock(NotificationService::class)->expects($this->never())->method('notify');

		$this->expectException(\RuntimeException::class);
		$this->service()->assignToOpening('hr', 5, 7);
	}
}
