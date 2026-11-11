<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\BackgroundJob\ExtractTextJob;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Service\AiService;
use OCA\Recruiting\Service\DocumentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The queued text extraction, and the AI intake analysis that waits for it.
 */
class ExtractTextJobTest extends TestCase {
	private DocumentService&MockObject $documents;
	private CandidateMapper&MockObject $candidateMapper;
	private AiService&MockObject $ai;
	private ExtractTextJob $job;

	protected function setUp(): void {
		$this->documents = $this->createMock(DocumentService::class);
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->ai = $this->createMock(AiService::class);
		$this->job = new ExtractTextJob($this->createMock(ITimeFactory::class), $this->documents, $this->candidateMapper, $this->ai, new NullLogger());
	}

	private function runJob(mixed $argument): void {
		(new \ReflectionMethod(ExtractTextJob::class, 'run'))->invoke($this->job, $argument);
	}

	private function candidate(?\DateTime $anonymizedAt = null): Candidate {
		$candidate = new Candidate();
		$candidate->setId(9);
		$candidate->setAnonymizedAt($anonymizedAt);
		return $candidate;
	}

	public function testTheTextIsExtractedBeforeTheIntakeAnalysisIsScheduled(): void {
		$order = [];
		$this->documents->expects($this->exactly(2))->method('extractStoredText')
			->willReturnCallback(function (int $id) use (&$order): bool {
				$order[] = 'extract:' . $id;
				return true;
			});
		$candidate = $this->candidate();
		$this->candidateMapper->method('find')->with(9)->willReturn($candidate);
		$this->ai->expects($this->once())->method('scheduleIntakeAnalysis')->with($candidate)
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'intake';
			});

		$this->runJob(['documentIds' => [3, 4], 'intakeCandidateId' => 9]);

		$this->assertSame(['extract:3', 'extract:4', 'intake'], $order);
	}

	public function testAFailingDocumentNeitherStopsTheOthersNorTheIntake(): void {
		$this->documents->method('extractStoredText')->willReturnCallback(
			static fn (int $id): bool => $id === 3 ? throw new \RuntimeException('storage down') : true,
		);
		$this->documents->expects($this->exactly(2))->method('extractStoredText');
		$this->candidateMapper->method('find')->willReturn($this->candidate());
		$this->ai->expects($this->once())->method('scheduleIntakeAnalysis');

		$this->runJob(['documentIds' => [3, 4], 'intakeCandidateId' => 9]);
	}

	public function testUploadsWithoutIntakeScheduleNoAnalysis(): void {
		$this->documents->expects($this->once())->method('extractStoredText')->with(5);
		$this->ai->expects($this->never())->method('scheduleIntakeAnalysis');

		$this->runJob(['documentIds' => [5], 'intakeCandidateId' => null]);
	}

	public function testDeletedOrAnonymizedCandidatesGetNoAnalysis(): void {
		$this->ai->expects($this->never())->method('scheduleIntakeAnalysis');

		$this->candidateMapper->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('gone')),
			$this->candidate(new \DateTime('2026-11-01')),
		);
		$this->runJob(['documentIds' => [], 'intakeCandidateId' => 9]);
		$this->runJob(['documentIds' => [], 'intakeCandidateId' => 9]);
	}

	public function testAMalformedArgumentDoesNothing(): void {
		$this->documents->expects($this->never())->method('extractStoredText');
		$this->ai->expects($this->never())->method('scheduleIntakeAnalysis');

		$this->runJob(null);
	}
}
