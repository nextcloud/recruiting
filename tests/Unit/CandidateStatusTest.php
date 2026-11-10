<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Service\CandidateStatus;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class CandidateStatusTest extends TestCase {
	public function testEveryStageMapsToACoarseStatus(): void {
		$this->assertSame(CandidateStatus::RECEIVED, CandidateStatus::forStage(Candidate::STAGE_NEW));
		$this->assertSame(CandidateStatus::IN_REVIEW, CandidateStatus::forStage(Candidate::STAGE_SCREENING));
		$this->assertSame(CandidateStatus::INTERVIEW, CandidateStatus::forStage(Candidate::STAGE_INTERVIEW));
		$this->assertSame(CandidateStatus::OFFER, CandidateStatus::forStage(Candidate::STAGE_OFFER));
		// Hired, rejected and withdrawn are indistinguishable from outside
		foreach (Candidate::TERMINAL_STAGES as $stage) {
			$this->assertSame(CandidateStatus::CLOSED, CandidateStatus::forStage($stage));
		}
	}

	public function testOnlyTerminalStagesAreClosed(): void {
		foreach (Candidate::ACTIVE_STAGES as $stage) {
			$this->assertNotSame(CandidateStatus::CLOSED, CandidateStatus::forStage($stage));
		}
	}

	public function testLabelsNeverNameInternalStages(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s) => $s);
		$candidate = new Candidate();
		$candidate->setStage(Candidate::STAGE_REJECTED);
		$label = CandidateStatus::label(CandidateStatus::of($candidate), $l);
		$this->assertSame('Closed', $label);
		$this->assertStringNotContainsStringIgnoringCase('reject', CandidateStatus::explanation(CandidateStatus::CLOSED, $l));
	}
}
