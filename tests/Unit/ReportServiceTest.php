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
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Service\OpeningService;
use OCA\Recruiting\Service\ReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class ReportServiceTest extends TestCase {
	private ReportService $service;

	protected function setUp(): void {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-08-16T12:00:00+00:00'));
		$this->service = new ReportService(
			$this->createMock(OpeningService::class),
			$this->createMock(CandidateMapper::class),
			$this->createMock(TimelineEventMapper::class),
			$this->createMock(OfferMapper::class),
			$timeFactory,
		);
	}

	private function candidate(int $id, string $createdAt, string $stage = 'new', ?string $hiredAt = null, ?string $reason = null): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setStage($stage);
		$candidate->setCreatedAt(new \DateTime($createdAt));
		if ($hiredAt !== null) {
			$candidate->setHiredAt(new \DateTime($hiredAt));
		}
		if ($reason !== null) {
			$candidate->setRejectionReason($reason);
		}
		return $candidate;
	}

	private function stageChange(int $candidateId, string $at, string $from, string $to): TimelineEvent {
		$event = new TimelineEvent();
		$event->setCandidateId($candidateId);
		$event->setType(TimelineEvent::TYPE_STAGE_CHANGED);
		$event->setCreatedAt(new \DateTime($at));
		$event->setData(json_encode(['from' => $from, 'to' => $to]));
		return $event;
	}

	public function testAverageDaysToHire(): void {
		$candidates = [
			$this->candidate(1, '2026-08-01T00:00:00+00:00', 'hired', '2026-08-11T00:00:00+00:00'), // 10 days
			$this->candidate(2, '2026-08-01T00:00:00+00:00', 'hired', '2026-08-21T00:00:00+00:00'), // 20 days
			$this->candidate(3, '2026-08-01T00:00:00+00:00', 'rejected'),                            // ignored
		];
		$this->assertSame(15.0, $this->service->averageDaysToHire($candidates));
		$this->assertNull($this->service->averageDaysToHire([$this->candidate(3, '2026-08-01T00:00:00+00:00')]));
	}

	public function testAverageDaysInStage(): void {
		$now = new \DateTimeImmutable('2026-08-16T00:00:00+00:00');
		// Candidate 1: new for 2 days, screening for 4 days, then interview until now (10 days)
		$candidates = [$this->candidate(1, '2026-08-01T00:00:00+00:00', 'interview')];
		$events = [1 => [
			$this->stageChange(1, '2026-08-03T00:00:00+00:00', 'new', 'screening'),
			$this->stageChange(1, '2026-08-07T00:00:00+00:00', 'screening', 'interview'),
		]];
		$avg = $this->service->averageDaysInStage($candidates, $events, $now);
		$this->assertSame(2.0, $avg['new']);
		$this->assertSame(4.0, $avg['screening']);
		$this->assertSame(9.0, $avg['interview']);
	}

	public function testTerminalCandidatesStopCounting(): void {
		$now = new \DateTimeImmutable('2026-12-31T00:00:00+00:00');
		// Rejected after 3 days in new — the months since must not count
		$candidates = [$this->candidate(1, '2026-08-01T00:00:00+00:00', 'rejected')];
		$events = [1 => [
			$this->stageChange(1, '2026-08-04T00:00:00+00:00', 'new', 'rejected'),
		]];
		$avg = $this->service->averageDaysInStage($candidates, $events, $now);
		$this->assertSame(3.0, $avg['new']);
		$this->assertArrayNotHasKey('screening', $avg);
	}

	public function testRejectionReasonsSortedByCount(): void {
		$candidates = [
			$this->candidate(1, '2026-08-01T00:00:00+00:00', 'rejected', null, 'not_qualified'),
			$this->candidate(2, '2026-08-01T00:00:00+00:00', 'rejected', null, 'better_candidate'),
			$this->candidate(3, '2026-08-01T00:00:00+00:00', 'rejected', null, 'better_candidate'),
			$this->candidate(4, '2026-08-01T00:00:00+00:00'),
		];
		$this->assertSame(
			['better_candidate' => 2, 'not_qualified' => 1],
			$this->service->rejectionReasons($candidates),
		);
	}

	public function testApplicationsPerWeekCoversTwelveWeeksAndCounts(): void {
		$now = new \DateTimeImmutable('2026-08-16T12:00:00+00:00'); // a Sunday in week 33
		$candidates = [
			$this->candidate(1, '2026-08-12T00:00:00+00:00'), // this week (33)
			$this->candidate(2, '2026-08-05T00:00:00+00:00'), // last week (32)
			$this->candidate(3, '2026-08-04T00:00:00+00:00'), // last week (32)
			$this->candidate(4, '2020-01-01T00:00:00+00:00'), // far outside the window
		];
		$weeks = $this->service->applicationsPerWeek($candidates, $now);
		$this->assertCount(12, $weeks);
		$this->assertSame('2026-W33', $weeks[11]['week']);
		$this->assertSame(1, $weeks[11]['count']);
		$this->assertSame('2026-W32', $weeks[10]['week']);
		$this->assertSame(2, $weeks[10]['count']);
		$this->assertSame(0, $weeks[0]['count']);
	}
}
