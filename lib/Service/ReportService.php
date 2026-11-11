<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\ReportMapper;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * The reports dashboard (spec §4.11): funnel, stage conversion,
 * time-in-stage, time-to-hire, rejection reasons, sources and
 * applications over time — for the applications received in a date range,
 * role-scoped through OpeningService::listForUser.
 *
 * Statistics are computed per opening from aggregate and narrow queries
 * (ReportMapper) and cached per opening (ReportCache); a user's overview
 * is composed from the blocks of the openings they may see.
 */
class ReportService {
	/** Ranges up to this many weeks are charted per week, longer ones per month */
	private const MAX_WEEKLY_WEEKS = 26;
	private const DEFAULT_MONTHS = 12;

	/** Pipeline order for "reached at least this stage" */
	public const FUNNEL_STAGES = [
		Candidate::STAGE_NEW,
		Candidate::STAGE_SCREENING,
		Candidate::STAGE_INTERVIEW,
		Candidate::STAGE_OFFER,
		Candidate::STAGE_HIRED,
	];

	/**
	 * Interviewers are scoped to their assigned candidates, so they must
	 * not see whole-opening funnels, rejection reasons or offer counts
	 * (spec §2/§4.11 grant reports to managers, observers and recruiters).
	 */
	private const REPORT_ROLES = [PermissionService::ROLE_RECRUITER, TeamMember::ROLE_MANAGER, TeamMember::ROLE_OBSERVER];

	public function __construct(
		private OpeningService $openings,
		private ReportMapper $reportMapper,
		private OfferMapper $offerMapper,
		private ReportCache $cache,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * @throws ValidationException on an invalid date range
	 */
	public function overview(string $uid, ?string $from = null, ?string $to = null): array {
		$range = $this->range($from, $to);
		$openings = $this->openings->listForUser($uid, self::REPORT_ROLES);
		$blocks = $this->blocks(array_map(static fn (array $o) => (int)$o['id'], $openings), $range);
		$openOffers = $this->offerMapper->countOpenByOpening();

		$perOpening = [];
		$totals = $this->emptyBlock();
		foreach ($openings as $opening) {
			$block = $blocks[(int)$opening['id']];
			$perOpening[] = $this->summarize($block, [
				'id' => $opening['id'],
				'title' => $opening['title'],
				'status' => $opening['status'],
				'openOffers' => $openOffers[(int)$opening['id']] ?? 0,
			]);
			$totals = $this->addBlocks($totals, $block);
		}
		$summary = $this->summarize($totals, []);

		return [
			'range' => [
				'from' => $range['from']->format('Y-m-d'),
				'to' => $range['to']->format('Y-m-d'),
			],
			'openings' => $perOpening,
			'totals' => [
				'candidates' => $summary['totalCount'],
				'hired' => $summary['stageCounts'][Candidate::STAGE_HIRED],
				'rejected' => $summary['stageCounts'][Candidate::STAGE_REJECTED],
				'avgDaysToHire' => $summary['avgDaysToHire'],
				'openOffers' => array_sum(array_column($perOpening, 'openOffers')),
				'rejectionReasons' => $summary['rejectionReasons'],
				'sources' => $summary['sources'],
				'funnel' => $summary['funnel'],
			],
			'applications' => $this->applicationsOverTime($totals['days'], $range['from'], $range['to']),
		];
	}

	/**
	 * The per-opening table of the overview as CSV, scoped exactly like
	 * the overview.
	 *
	 * @return array{name: string, content: string}
	 * @throws ValidationException on an invalid date range
	 */
	public function exportCsv(string $uid, ?string $from = null, ?string $to = null): array {
		$report = $this->overview($uid, $from, $to);
		$stages = array_merge(Candidate::ACTIVE_STAGES, Candidate::TERMINAL_STAGES);
		$funnel = array_slice(self::FUNNEL_STAGES, 1);

		$sources = [];
		foreach ($report['openings'] as $opening) {
			$sources = array_merge($sources, array_map(strval(...), array_keys($opening['sources'])));
		}
		$sources = array_values(array_unique($sources));
		sort($sources);

		$header = [
			$this->l10n->t('Opening'),
			$this->l10n->t('Status'),
			$this->l10n->t('Applications'),
		];
		foreach ($stages as $stage) {
			$header[] = $this->l10n->t('Now in "%s"', [CandidateStatus::stageLabel($stage, $this->l10n)]);
		}
		foreach ($funnel as $stage) {
			$header[] = $this->l10n->t('Reached "%s"', [CandidateStatus::stageLabel($stage, $this->l10n)]);
		}
		$header[] = $this->l10n->t('Avg. days to hire');
		$header[] = $this->l10n->t('Open offers');
		foreach ($sources as $source) {
			$header[] = $this->l10n->t('Source: %s', [$this->sourceLabel($source)]);
		}

		$rows = [$header];
		foreach ($report['openings'] as $opening) {
			$row = [
				(string)$opening['title'],
				$this->statusLabel((string)$opening['status']),
				$opening['totalCount'],
			];
			foreach ($stages as $stage) {
				$row[] = $opening['stageCounts'][$stage];
			}
			foreach ($funnel as $stage) {
				$row[] = $opening['funnel'][$stage];
			}
			$row[] = $opening['avgDaysToHire'] ?? '';
			$row[] = $opening['openOffers'];
			foreach ($sources as $source) {
				$row[] = $opening['sources'][$source] ?? 0;
			}
			$rows[] = $row;
		}

		$name = 'recruiting-report-' . $report['range']['from'] . '-' . $report['range']['to'] . '.csv';
		return ['name' => $name, 'content' => self::toCsv($rows)];
	}

	/**
	 * RFC 4180 CSV with a UTF-8 byte order mark (so spreadsheet apps detect
	 * the encoding). Text cells are neutralized against formula injection.
	 *
	 * @param array<int, array<int, string|int|float>> $rows
	 */
	public static function toCsv(array $rows): string {
		$handle = fopen('php://temp', 'r+b');
		if ($handle === false) {
			throw new \RuntimeException('Could not open a temporary stream');
		}
		foreach ($rows as $row) {
			fputcsv($handle, array_map(self::csvCell(...), $row), ',', '"', '');
		}
		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);
		return "\u{FEFF}" . $csv;
	}

	/**
	 * Spreadsheet apps evaluate cells starting with = + - @ (or a tab or
	 * carriage return) as formulas; an opening title like "=HYPERLINK(…)"
	 * must stay text.
	 */
	public static function csvCell(string|int|float $value): string|int|float {
		if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * The reporting window in whole UTC days, by default the last 12
	 * months. "to" is the last day included, "end" the exclusive bound.
	 *
	 * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, end: \DateTimeImmutable, key: string}
	 * @throws ValidationException
	 */
	public function range(?string $from, ?string $to): array {
		$today = \DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime())
			->setTimezone(new \DateTimeZone('UTC'))
			->setTime(0, 0);
		$toDay = ($to !== null && $to !== '') ? $this->parseDay($to) : $today;
		$fromDay = ($from !== null && $from !== '') ? $this->parseDay($from) : $toDay->modify('-' . self::DEFAULT_MONTHS . ' months');
		if ($fromDay > $toDay) {
			throw new ValidationException($this->l10n->t('The start date must not be after the end date'));
		}
		return [
			'from' => $fromDay,
			'to' => $toDay,
			'end' => $toDay->modify('+1 day'),
			'key' => $fromDay->format('Ymd') . '-' . $toDay->format('Ymd'),
		];
	}

	/**
	 * Cached statistics blocks per opening; missing ones are computed for
	 * all of them at once, in a constant number of queries.
	 *
	 * @param int[] $openingIds
	 * @param array{from: \DateTimeImmutable, to: \DateTimeImmutable, end: \DateTimeImmutable, key: string} $range
	 * @return array<int, array>
	 */
	private function blocks(array $openingIds, array $range): array {
		$blocks = [];
		$keys = [];
		foreach ($openingIds as $id) {
			$cached = $this->cache->lookup($id, $range['key']);
			if ($cached['block'] !== null) {
				$blocks[$id] = $cached['block'];
			} else {
				$keys[$id] = $cached['key'];
			}
		}
		if ($keys === []) {
			return $blocks;
		}

		$missing = array_keys($keys);
		$stages = $this->reportMapper->countBy('stage', $missing, $range['from'], $range['end']);
		$sources = $this->reportMapper->countBy('source', $missing, $range['from'], $range['end']);
		$reasons = $this->reportMapper->countBy('rejection_reason', $missing, $range['from'], $range['end']);
		$applications = $this->reportMapper->applications($missing, $range['from'], $range['end']);
		$events = $this->reportMapper->stageChanges($missing, $range['from'], $range['end']);
		$now = $this->timeFactory->getDateTime()->getTimestamp();

		foreach ($missing as $id) {
			$block = $this->buildBlock($stages[$id] ?? [], $sources[$id] ?? [], $reasons[$id] ?? [], $applications[$id] ?? [], $events, $now);
			$this->cache->store($keys[$id], $block);
			$blocks[$id] = $block;
		}
		return $blocks;
	}

	/**
	 * One opening's statistics in additive form (sums and counts), so blocks
	 * can be cached independently and combined into totals.
	 *
	 * @param array<string, int> $stageCounts
	 * @param array<string, int> $sources
	 * @param array<string, int> $reasons
	 * @param list<array{id: int, stage: string, createdAt: int, hiredAt: ?int}> $applications
	 * @param array<int, list<array{at: int, from: string, to: string}>> $eventsByCandidate
	 */
	public function buildBlock(array $stageCounts, array $sources, array $reasons, array $applications, array $eventsByCandidate, int $now): array {
		$block = $this->emptyBlock();
		foreach ($stageCounts as $stage => $count) {
			$block['stageCounts'][$stage] = $count;
		}
		$block['sources'] = $sources;
		$block['rejectionReasons'] = $reasons;

		foreach ($applications as $application) {
			if ($application['hiredAt'] !== null) {
				$block['hire']['seconds'] += max(0, $application['hiredAt'] - $application['createdAt']);
				$block['hire']['count']++;
			}
			$day = gmdate('Y-m-d', $application['createdAt']);
			$block['days'][$day] = ($block['days'][$day] ?? 0) + 1;
		}
		$block['stageTime'] = $this->stageTime($applications, $eventsByCandidate, $now);
		$block['reached'] = $this->reached($applications, $eventsByCandidate);
		return $block;
	}

	/**
	 * Time spent per active stage, reconstructed from the stage changes. A
	 * candidate still sitting in a stage counts up to "now".
	 *
	 * @param list<array{id: int, stage: string, createdAt: int, hiredAt: ?int}> $applications
	 * @param array<int, list<array{at: int, from: string, to: string}>> $eventsByCandidate
	 * @return array{sums: array<string, int>, counts: array<string, int>}
	 */
	public function stageTime(array $applications, array $eventsByCandidate, int $now): array {
		$sums = [];
		$counts = [];
		foreach ($applications as $application) {
			$cursor = $application['createdAt'];
			$stage = Candidate::STAGE_NEW;
			$periods = [];
			foreach ($eventsByCandidate[$application['id']] ?? [] as $event) {
				$periods[] = [$stage, $cursor, $event['at']];
				$stage = $event['to'] !== '' ? $event['to'] : $stage;
				$cursor = $event['at'];
			}
			if (!in_array($stage, Candidate::TERMINAL_STAGES, true)) {
				$periods[] = [$stage, $cursor, $now];
			}
			foreach ($periods as [$periodStage, $start, $end]) {
				if (!in_array($periodStage, Candidate::ACTIVE_STAGES, true)) {
					continue;
				}
				$sums[$periodStage] = ($sums[$periodStage] ?? 0) + max(0, $end - $start);
				$counts[$periodStage] = ($counts[$periodStage] ?? 0) + 1;
			}
		}
		return ['sums' => $sums, 'counts' => $counts];
	}

	/**
	 * Stage conversion: how many applications reached at least each
	 * pipeline stage — judged by their current stage and every stage their
	 * history passed through (a candidate rejected after the interview
	 * still reached "Interview").
	 *
	 * @param list<array{id: int, stage: string, createdAt: int, hiredAt: ?int}> $applications
	 * @param array<int, list<array{at: int, from: string, to: string}>> $eventsByCandidate
	 * @return array<string, int>
	 */
	public function reached(array $applications, array $eventsByCandidate): array {
		$rank = array_flip(self::FUNNEL_STAGES);
		$reached = array_fill_keys(self::FUNNEL_STAGES, 0);
		foreach ($applications as $application) {
			$highest = $rank[$application['stage']] ?? 0;
			foreach ($eventsByCandidate[$application['id']] ?? [] as $event) {
				$highest = max($highest, $rank[$event['from']] ?? 0, $rank[$event['to']] ?? 0);
			}
			foreach (self::FUNNEL_STAGES as $stage) {
				if ($rank[$stage] <= $highest) {
					$reached[$stage]++;
				}
			}
		}
		return $reached;
	}

	/**
	 * Applications per ISO week (ranges up to 26 weeks) or per month,
	 * oldest first, covering the whole range.
	 *
	 * @param array<string, int> $days Y-m-d => applications
	 * @return array{granularity: string, buckets: list<array{period: string, count: int}>}
	 */
	public function applicationsOverTime(array $days, \DateTimeImmutable $from, \DateTimeImmutable $to): array {
		$weekly = $from->modify('monday this week')->diff($to)->days < self::MAX_WEEKLY_WEEKS * 7;
		$format = $weekly ? 'o-\WW' : 'Y-m';
		$cursor = $weekly ? $from->modify('monday this week') : $from->modify('first day of this month');
		$step = $weekly ? '+1 week' : '+1 month';

		$buckets = [];
		while ($cursor <= $to) {
			$buckets[$cursor->format($format)] = 0;
			$cursor = $cursor->modify($step);
		}
		foreach ($days as $day => $count) {
			$date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$day, new \DateTimeZone('UTC'));
			if ($date === false || $date < $from || $date > $to) {
				continue;
			}
			$key = $date->format($format);
			if (array_key_exists($key, $buckets)) {
				$buckets[$key] += $count;
			}
		}

		$result = [];
		foreach ($buckets as $period => $count) {
			$result[] = ['period' => (string)$period, 'count' => $count];
		}
		return ['granularity' => $weekly ? 'week' : 'month', 'buckets' => $result];
	}

	/**
	 * Known sources get a label; anything else is shown as stored.
	 */
	public function sourceLabel(string $source): string {
		return match ($source) {
			Candidate::SOURCE_EMAIL => $this->l10n->t('Email application'),
			Candidate::SOURCE_WEB => $this->l10n->t('Web form'),
			Candidate::SOURCE_MANUAL => $this->l10n->t('Added manually'),
			Candidate::SOURCE_POOL => $this->l10n->t('Talent pool'),
			'referral' => $this->l10n->t('Employee referral'),
			default => $source,
		};
	}

	/**
	 * Averages and derived figures of a block, merged into $base.
	 */
	private function summarize(array $block, array $base): array {
		$active = 0;
		foreach (Candidate::ACTIVE_STAGES as $stage) {
			$active += $block['stageCounts'][$stage];
		}
		$avgDaysInStage = [];
		foreach (Candidate::ACTIVE_STAGES as $stage) {
			$days = self::averageDays($block['stageTime']['sums'][$stage] ?? 0, $block['stageTime']['counts'][$stage] ?? 0);
			if ($days !== null) {
				$avgDaysInStage[$stage] = $days;
			}
		}
		$reasons = $block['rejectionReasons'];
		arsort($reasons);
		$sources = $block['sources'];
		arsort($sources);

		return array_merge($base, [
			'stageCounts' => $block['stageCounts'],
			'activeCount' => $active,
			'totalCount' => array_sum($block['stageCounts']),
			'avgDaysToHire' => self::averageDays($block['hire']['seconds'], $block['hire']['count']),
			'avgDaysInStage' => $avgDaysInStage,
			'rejectionReasons' => $reasons,
			'sources' => $sources,
			'funnel' => $block['reached'],
		]);
	}

	private function emptyBlock(): array {
		return [
			'stageCounts' => array_fill_keys(array_merge(Candidate::ACTIVE_STAGES, Candidate::TERMINAL_STAGES), 0),
			'sources' => [],
			'rejectionReasons' => [],
			'hire' => ['seconds' => 0, 'count' => 0],
			'stageTime' => ['sums' => [], 'counts' => []],
			'reached' => array_fill_keys(self::FUNNEL_STAGES, 0),
			'days' => [],
		];
	}

	private function addBlocks(array $a, array $b): array {
		$sum = static function (array $x, array $y): array {
			foreach ($y as $key => $value) {
				$x[$key] = ($x[$key] ?? 0) + $value;
			}
			return $x;
		};
		return [
			'stageCounts' => $sum($a['stageCounts'], $b['stageCounts']),
			'sources' => $sum($a['sources'], $b['sources']),
			'rejectionReasons' => $sum($a['rejectionReasons'], $b['rejectionReasons']),
			'hire' => $sum($a['hire'], $b['hire']),
			'stageTime' => [
				'sums' => $sum($a['stageTime']['sums'], $b['stageTime']['sums']),
				'counts' => $sum($a['stageTime']['counts'], $b['stageTime']['counts']),
			],
			'reached' => $sum($a['reached'], $b['reached']),
			'days' => $sum($a['days'], $b['days']),
		];
	}

	private static function averageDays(int $seconds, int $count): ?float {
		if ($count === 0) {
			return null;
		}
		return round((float)$seconds / (float)($count * 86400), 1);
	}

	/**
	 * @throws ValidationException
	 */
	private function parseDay(string $value): \DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw new ValidationException($this->l10n->t('Invalid date: %s', [$value]));
		}
		return $date;
	}

	private function statusLabel(string $status): string {
		return match ($status) {
			Opening::STATUS_OPEN => $this->l10n->t('Open'),
			Opening::STATUS_ON_HOLD => $this->l10n->t('On hold'),
			Opening::STATUS_CLOSED => $this->l10n->t('Closed'),
			default => $status,
		};
	}
}
