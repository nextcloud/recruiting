<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\ReportMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\OpeningService;
use OCA\Recruiting\Service\ReportCache;
use OCA\Recruiting\Service\ReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReportServiceTest extends TestCase {
	private OpeningService&MockObject $openings;
	private ReportMapper&MockObject $reportMapper;
	private OfferMapper&MockObject $offerMapper;
	private ReportCache $cache;
	private ReportService $service;

	protected function setUp(): void {
		$this->openings = $this->createMock(OpeningService::class);
		$this->reportMapper = $this->createMock(ReportMapper::class);
		$this->offerMapper = $this->createMock(OfferMapper::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(true);
		$cacheFactory->method('createDistributed')->willReturn(new ArrayCache());
		$this->cache = new ReportCache($cacheFactory);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-08-16T12:00:00+00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$this->service = new ReportService(
			$this->openings,
			$this->reportMapper,
			$this->offerMapper,
			$this->cache,
			$timeFactory,
			$l10n,
		);
	}

	private static function ts(string $date): int {
		return (new \DateTimeImmutable($date))->getTimestamp();
	}

	/**
	 * @return array{id: int, stage: string, createdAt: int, hiredAt: ?int}
	 */
	private static function application(int $id, string $createdAt, string $stage = 'new', ?string $hiredAt = null): array {
		return [
			'id' => $id,
			'stage' => $stage,
			'createdAt' => self::ts($createdAt),
			'hiredAt' => $hiredAt !== null ? self::ts($hiredAt) : null,
		];
	}

	/**
	 * @return array{at: int, from: string, to: string}
	 */
	private static function change(string $at, string $from, string $to): array {
		return ['at' => self::ts($at), 'from' => $from, 'to' => $to];
	}

	private function opening(int $id, string $title = 'Opening'): array {
		return ['id' => $id, 'title' => $title, 'status' => 'open'];
	}

	/**
	 * Opening 1 has a hire after 10 days and a rejection, opening 2 a hire
	 * after 20 days.
	 */
	private function stubTwoOpenings(): void {
		$this->reportMapper->method('countBy')->willReturnCallback(static fn (string $column) => match ($column) {
			'stage' => [1 => ['hired' => 1, 'rejected' => 1], 2 => ['hired' => 1]],
			'source' => [1 => ['web' => 1, 'email' => 1], 2 => ['web' => 1]],
			'rejection_reason' => [1 => ['not_qualified' => 1]],
		});
		$this->reportMapper->method('applications')->willReturn([
			1 => [
				self::application(10, '2026-08-01T00:00:00+00:00', 'hired', '2026-08-11T00:00:00+00:00'),
				self::application(11, '2026-08-03T00:00:00+00:00', 'rejected'),
			],
			2 => [self::application(20, '2026-07-01T00:00:00+00:00', 'hired', '2026-07-21T00:00:00+00:00')],
		]);
		$this->offerMapper->method('countOpenByOpening')->willReturn([2 => 3]);
	}

	public function testOverviewComposesOpeningsAndTotals(): void {
		$this->openings->expects($this->once())->method('listForUser')
			->with('hr', ['recruiter', 'manager', 'observer'])
			->willReturn([$this->opening(1, 'Developer'), $this->opening(2, 'Designer')]);
		$this->stubTwoOpenings();

		$report = $this->service->overview('hr');

		$this->assertSame(['from' => '2025-08-16', 'to' => '2026-08-16'], $report['range']);
		$this->assertCount(2, $report['openings']);
		$developer = $report['openings'][0];
		$this->assertSame('Developer', $developer['title']);
		$this->assertSame(2, $developer['totalCount']);
		$this->assertSame(0, $developer['activeCount']);
		$this->assertSame(10.0, $developer['avgDaysToHire']);
		$this->assertSame(['not_qualified' => 1], $developer['rejectionReasons']);
		$this->assertSame(0, $developer['openOffers']);
		$this->assertSame(3, $report['openings'][1]['openOffers']);

		$this->assertSame(3, $report['totals']['candidates']);
		$this->assertSame(2, $report['totals']['hired']);
		$this->assertSame(1, $report['totals']['rejected']);
		$this->assertSame(15.0, $report['totals']['avgDaysToHire']);
		$this->assertSame(3, $report['totals']['openOffers']);
		$this->assertSame(['web' => 2, 'email' => 1], $report['totals']['sources']);
		$this->assertSame(['not_qualified' => 1], $report['totals']['rejectionReasons']);
		$this->assertSame(3, $report['totals']['funnel']['new']);
		$this->assertSame(2, $report['totals']['funnel']['hired']);
		$this->assertSame('month', $report['applications']['granularity']);
	}

	public function testOpeningsWithoutApplicationsReportZeros(): void {
		$this->openings->method('listForUser')->willReturn([$this->opening(5)]);
		$report = $this->service->overview('hr');
		$this->assertSame(0, $report['openings'][0]['totalCount']);
		$this->assertNull($report['openings'][0]['avgDaysToHire']);
		$this->assertSame([], $report['openings'][0]['avgDaysInStage']);
		$this->assertSame(0, $report['totals']['funnel']['new']);
	}

	public function testNoVisibleOpeningsMeansNoQueries(): void {
		$this->openings->method('listForUser')->willReturn([]);
		$this->reportMapper->expects($this->never())->method('countBy');
		$this->reportMapper->expects($this->never())->method('applications');
		$this->assertSame([], $this->service->overview('interviewer1')['openings']);
	}

	public function testCachedBlocksAreNotRecomputed(): void {
		$this->openings->method('listForUser')->willReturn([$this->opening(1), $this->opening(2)]);
		$this->stubTwoOpenings();
		$this->reportMapper->expects($this->exactly(3))->method('countBy');
		$this->reportMapper->expects($this->once())->method('applications');

		$first = $this->service->overview('hr');
		$second = $this->service->overview('hr');
		$this->assertSame($first, $second);
	}

	public function testBlocksAreSharedBetweenUsers(): void {
		$this->openings->method('listForUser')->willReturnCallback(fn (string $uid) => $uid === 'hr'
			? [$this->opening(1), $this->opening(2)]
			: [$this->opening(2)]);
		$this->stubTwoOpenings();
		$this->reportMapper->expects($this->once())->method('applications');

		$this->service->overview('hr');
		$report = $this->service->overview('manager2');
		$this->assertCount(1, $report['openings']);
		$this->assertSame(1, $report['totals']['candidates']);
	}

	public function testInvalidationRecomputesOnlyThatOpening(): void {
		$this->openings->method('listForUser')->willReturn([$this->opening(1), $this->opening(2)]);
		$this->stubTwoOpenings();
		$requested = [];
		$this->reportMapper->method('stageChanges')->willReturnCallback(static function (array $ids) use (&$requested) {
			$requested[] = $ids;
			return [];
		});

		$this->service->overview('hr');
		$this->cache->invalidate(2);
		$this->service->overview('hr');
		$this->cache->invalidate(null);
		$this->service->overview('hr');

		$this->assertSame([[1, 2], [2], [1, 2]], $requested);
	}

	public function testDifferentRangesAreCachedSeparately(): void {
		$this->openings->method('listForUser')->willReturn([$this->opening(1)]);
		$this->stubTwoOpenings();
		$this->reportMapper->expects($this->exactly(2))->method('applications');
		$this->service->overview('hr', '2026-01-01', '2026-06-30');
		$this->service->overview('hr', '2026-01-01', '2026-07-31');
	}

	public function testRangeIsPassedAsWholeDays(): void {
		$this->openings->method('listForUser')->willReturn([$this->opening(1)]);
		$this->reportMapper->expects($this->once())->method('applications')
			->with(
				[1],
				$this->callback(static fn (\DateTimeInterface $from) => $from->format(DATE_ATOM) === '2026-03-01T00:00:00+00:00'),
				$this->callback(static fn (\DateTimeInterface $end) => $end->format(DATE_ATOM) === '2026-04-01T00:00:00+00:00'),
			)
			->willReturn([]);
		$report = $this->service->overview('hr', '2026-03-01', '2026-03-31');
		$this->assertSame(['from' => '2026-03-01', 'to' => '2026-03-31'], $report['range']);
	}

	public function testInvalidDatesAreRejected(): void {
		$this->expectException(ValidationException::class);
		$this->service->range('2026-02-30', null);
	}

	public function testStartAfterEndIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->service->range('2026-05-01', '2026-04-01');
	}

	public function testAverageDaysInStage(): void {
		$now = self::ts('2026-08-16T00:00:00+00:00');
		// new for 2 days, screening for 4 days, then interview until now (9 days)
		$applications = [self::application(1, '2026-08-01T00:00:00+00:00', 'interview')];
		$events = [1 => [
			self::change('2026-08-03T00:00:00+00:00', 'new', 'screening'),
			self::change('2026-08-07T00:00:00+00:00', 'screening', 'interview'),
		]];

		$time = $this->service->stageTime($applications, $events, $now);
		$this->assertSame(['new' => 2 * 86400, 'screening' => 4 * 86400, 'interview' => 9 * 86400], $time['sums']);

		// Through the overview, "now" is 2026-08-16T12:00
		$this->openings->method('listForUser')->willReturn([$this->opening(1)]);
		$this->reportMapper->method('countBy')->willReturn([1 => ['interview' => 1]]);
		$this->reportMapper->method('applications')->willReturn([1 => $applications]);
		$this->reportMapper->method('stageChanges')->willReturn($events);
		$avg = $this->service->overview('hr')['openings'][0]['avgDaysInStage'];
		$this->assertSame(['new' => 2.0, 'screening' => 4.0, 'interview' => 9.5], $avg);
	}

	public function testTerminalCandidatesStopCounting(): void {
		$now = self::ts('2026-12-31T00:00:00+00:00');
		// Rejected after 3 days in new — the months since must not count
		$applications = [self::application(1, '2026-08-01T00:00:00+00:00', 'rejected')];
		$events = [1 => [self::change('2026-08-04T00:00:00+00:00', 'new', 'rejected')]];
		$time = $this->service->stageTime($applications, $events, $now);
		$this->assertSame(['new' => 3 * 86400], $time['sums']);
		$this->assertSame(['new' => 1], $time['counts']);
	}

	public function testReachedCountsTheFurthestStageOfTheHistory(): void {
		$applications = [
			self::application(1, '2026-08-01T00:00:00+00:00', 'new'),
			self::application(2, '2026-08-01T00:00:00+00:00', 'rejected'),
			self::application(3, '2026-08-01T00:00:00+00:00', 'screening'),
			self::application(4, '2026-08-01T00:00:00+00:00', 'hired'),
		];
		$events = [
			// rejected after the interview
			2 => [
				self::change('2026-08-02T00:00:00+00:00', 'new', 'interview'),
				self::change('2026-08-03T00:00:00+00:00', 'interview', 'rejected'),
			],
			// moved back from the offer stage
			3 => [self::change('2026-08-04T00:00:00+00:00', 'offer', 'screening')],
		];
		$this->assertSame(
			['new' => 4, 'screening' => 3, 'interview' => 3, 'offer' => 2, 'hired' => 1],
			$this->service->reached($applications, $events),
		);
	}

	public function testShortRangesAreChartedPerWeek(): void {
		$days = [
			'2026-08-12' => 1, // week 33
			'2026-08-05' => 1, // week 32
			'2026-08-04' => 1, // week 32
			'2020-01-01' => 5, // outside the range
		];
		$chart = $this->service->applicationsOverTime(
			$days,
			new \DateTimeImmutable('2026-05-25T00:00:00+00:00'),
			new \DateTimeImmutable('2026-08-16T00:00:00+00:00'),
		);
		$this->assertSame('week', $chart['granularity']);
		$this->assertCount(12, $chart['buckets']);
		$this->assertSame(['period' => '2026-W22', 'count' => 0], $chart['buckets'][0]);
		$this->assertSame(['period' => '2026-W32', 'count' => 2], $chart['buckets'][10]);
		$this->assertSame(['period' => '2026-W33', 'count' => 1], $chart['buckets'][11]);
	}

	public function testLongRangesAreChartedPerMonth(): void {
		$chart = $this->service->applicationsOverTime(
			['2025-08-20' => 2, '2026-08-01' => 1, '2026-08-15' => 1],
			new \DateTimeImmutable('2025-08-16T00:00:00+00:00'),
			new \DateTimeImmutable('2026-08-16T00:00:00+00:00'),
		);
		$this->assertSame('month', $chart['granularity']);
		$this->assertCount(13, $chart['buckets']);
		$this->assertSame(['period' => '2025-08', 'count' => 2], $chart['buckets'][0]);
		$this->assertSame(['period' => '2026-08', 'count' => 2], $chart['buckets'][12]);
	}

	public function testSourceLabelsKnownAndRawUnknown(): void {
		$this->assertSame('Web form', $this->service->sourceLabel('web'));
		$this->assertSame('Employee referral', $this->service->sourceLabel('referral'));
		$this->assertSame('jobboard-x', $this->service->sourceLabel('jobboard-x'));
	}

	public function testCsvExportIsScopedAndNeutralizesFormulas(): void {
		$this->openings->expects($this->once())->method('listForUser')
			->with('manager1', ['recruiter', 'manager', 'observer'])
			->willReturn([$this->opening(1, '=HYPERLINK("http://evil","x")')]);
		$this->reportMapper->method('countBy')->willReturnCallback(static fn (string $column) => match ($column) {
			'stage' => [1 => ['new' => 2]],
			'source' => [1 => ['web' => 1, 'referral' => 1]],
			'rejection_reason' => [],
		});
		$this->reportMapper->method('applications')->willReturn([1 => [
			self::application(1, '2026-08-01T00:00:00+00:00'),
			self::application(2, '2026-08-02T00:00:00+00:00'),
		]]);

		$csv = $this->service->exportCsv('manager1', '2026-08-01', '2026-08-16');

		$this->assertSame('recruiting-report-2026-08-01-2026-08-16.csv', $csv['name']);
		$this->assertStringStartsWith("\u{FEFF}", $csv['content']);
		$lines = explode("\n", trim(substr($csv['content'], 3)));
		$this->assertCount(2, $lines);
		$header = str_getcsv($lines[0], ',', '"', '');
		$this->assertSame('Opening', $header[0]);
		$this->assertContains('Source: Employee referral', $header);
		$this->assertContains('Source: Web form', $header);
		$row = str_getcsv($lines[1], ',', '"', '');
		$this->assertSame('\'=HYPERLINK("http://evil","x")', $row[0]);
		$this->assertSame('Open', $row[1]);
		$this->assertSame('2', $row[2]);
	}

	public function testCsvCellPrefixesFormulaTriggers(): void {
		foreach (['=1+1', '+49 30', '-2', '@SUM(A1)', "\tx"] as $value) {
			$this->assertSame("'" . $value, ReportService::csvCell($value));
		}
		$this->assertSame('Developer', ReportService::csvCell('Developer'));
		$this->assertSame(-3, ReportService::csvCell(-3));
		$this->assertSame(1.5, ReportService::csvCell(1.5));
		$this->assertSame('', ReportService::csvCell(''));
	}
}
