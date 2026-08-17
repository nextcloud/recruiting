<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\StageService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class StageServiceTest extends TestCase {
	private CandidateMapper $candidateMapper;
	private StageService $service;

	protected function setUp(): void {
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-08-16T12:00:00+00:00'));
		$this->service = new StageService(
			$this->candidateMapper,
			$this->createMock(TimelineService::class),
			$this->createMock(ActivityPublisher::class),
			$timeFactory,
		);
	}

	private function candidate(string $stage): Candidate {
		$candidate = new Candidate();
		$candidate->setId(1);
		$candidate->setStage($stage);
		if ($stage === Candidate::STAGE_WITHDRAWN) {
			$candidate->setWithdrawnAt(new \DateTime('2026-08-01T00:00:00+00:00'));
		}
		if ($stage === Candidate::STAGE_REJECTED) {
			$candidate->setRejectedAt(new \DateTime('2026-08-01T00:00:00+00:00'));
			$candidate->setRejectionReason('better_candidate');
		}
		return $candidate;
	}

	public function testNormalMoveApplies(): void {
		$candidate = $this->candidate(Candidate::STAGE_SCREENING);
		$this->candidateMapper->expects($this->once())->method('update');

		$this->assertTrue($this->service->apply($candidate, Candidate::STAGE_INTERVIEW, 'hr'));
		$this->assertSame(Candidate::STAGE_INTERVIEW, $candidate->getStage());
	}

	public function testSameStageIsANoop(): void {
		$candidate = $this->candidate(Candidate::STAGE_SCREENING);
		$this->candidateMapper->expects($this->never())->method('update');
		$this->assertFalse($this->service->apply($candidate, Candidate::STAGE_SCREENING, 'hr'));
	}

	/**
	 * A late offer acceptance (or a candidate confirming an old interview
	 * link) must never resurrect somebody who was rejected or withdrew.
	 */
	public function testTerminalCandidatesAreNotResurrected(): void {
		$this->candidateMapper->expects($this->never())->method('update');

		foreach ([Candidate::STAGE_REJECTED, Candidate::STAGE_WITHDRAWN, Candidate::STAGE_HIRED] as $terminal) {
			$candidate = $this->candidate($terminal);
			$this->assertFalse($this->service->apply($candidate, Candidate::STAGE_HIRED, 'hr'));
			$this->assertSame($terminal, $candidate->getStage());
		}
	}

	public function testWithdrawalDataSurvivesABlockedMove(): void {
		$candidate = $this->candidate(Candidate::STAGE_WITHDRAWN);
		$this->service->apply($candidate, Candidate::STAGE_HIRED, 'hr');
		$this->assertNotNull($candidate->getWithdrawnAt());
	}

	public function testRejectionDataSurvivesABlockedMove(): void {
		$candidate = $this->candidate(Candidate::STAGE_REJECTED);
		$this->service->apply($candidate, Candidate::STAGE_HIRED, 'hr');
		$this->assertNotNull($candidate->getRejectedAt());
		$this->assertSame('better_candidate', $candidate->getRejectionReason());
	}

	/**
	 * The manual move in the UI is the one place that may reopen a
	 * finished candidate.
	 */
	public function testExplicitReopeningIsAllowed(): void {
		$candidate = $this->candidate(Candidate::STAGE_WITHDRAWN);
		$this->candidateMapper->expects($this->once())->method('update');

		$this->assertTrue($this->service->apply($candidate, Candidate::STAGE_INTERVIEW, 'hr', [], allowFromTerminal: true));
		$this->assertSame(Candidate::STAGE_INTERVIEW, $candidate->getStage());
		$this->assertNull($candidate->getWithdrawnAt());
	}
}
