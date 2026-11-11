<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\SeenMailMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The intake ledger (attempts counted before processing, the candidate
 * remembered for a resume) and the offer status claim, against a recording
 * query builder.
 */
class SeenMailMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	/** @var list<array<string, mixed>|false> rows the SELECTs return, in order */
	private array $rows = [];
	/** @var list<array<string, mixed>> the SET clauses of every UPDATE */
	private array $updates = [];
	/** @var list<array<string, mixed>> */
	private array $inserts = [];
	private int $insertResult = 1;
	private int $affected = 1;
	private SeenMailMapper $mapper;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->builder());
		$this->db->method('insertIgnoreConflict')->willReturnCallback(function (string $table, array $values): int {
			$this->inserts[] = $values;
			return $this->insertResult;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-11T10:00:00Z'));
		$this->mapper = new SeenMailMapper($this->db, $time);
	}

	private function builder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$set = [];
		foreach (['select', 'from', 'update', 'where', 'andWhere', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('set')->willReturnCallback(function (string $column, $value) use ($qb, &$set) {
			$set[$column] = $value;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($x, $y) => $x . ' = ' . json_encode($y));
		$qb->method('expr')->willReturn($expr);
		$qb->method('executeQuery')->willReturnCallback(function () {
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturn(array_shift($this->rows) ?? false);
			return $result;
		});
		$qb->method('executeStatement')->willReturnCallback(function () use (&$set) {
			$this->updates[] = $set;
			return $this->affected;
		});
		return $qb;
	}

	public function testTheFirstAttemptInsertsAPendingRow(): void {
		$this->rows = [false];

		$this->assertSame(['done' => false, 'attempts' => 1, 'candidateId' => null], $this->mapper->beginAttempt('<a@x>'));
		$this->assertSame(1, $this->inserts[0]['pending']);
		$this->assertSame(1, $this->inserts[0]['attempts']);
	}

	public function testALaterAttemptCountsUpAndReportsTheCandidate(): void {
		$this->rows = [['pending' => 1, 'attempts' => 2, 'candidate_id' => '55']];

		$this->assertSame(['done' => false, 'attempts' => 3, 'candidateId' => 55], $this->mapper->beginAttempt('<a@x>'));
		$this->assertSame([['attempts' => 3]], $this->updates);
	}

	public function testAnIngestedMailIsDoneWithoutCounting(): void {
		$this->rows = [['pending' => 0, 'attempts' => 0, 'candidate_id' => 55]];

		$this->assertTrue($this->mapper->beginAttempt('<a@x>')['done']);
		$this->assertSame([], $this->updates);
	}

	public function testARowInsertedByAParallelRunIsCountedOn(): void {
		$this->rows = [false, ['pending' => 1, 'attempts' => 1, 'candidate_id' => null]];
		$this->insertResult = 0;

		$this->assertSame(2, $this->mapper->beginAttempt('<a@x>')['attempts']);
	}

	public function testSuccessMarksTheMailDoneAndStartsTheCountOver(): void {
		$this->mapper->remember('<a@x>');

		$this->assertSame(0, $this->updates[0]['pending']);
		$this->assertSame(0, $this->updates[0]['attempts']);
		$this->assertSame([], $this->inserts);
	}

	public function testQuarantineStartsTheCountOverAndKeepsTheReason(): void {
		$this->mapper->markQuarantined('<a@x>', 'too large');

		$this->assertSame(0, $this->updates[0]['attempts']);
		$this->assertSame('too large', $this->updates[0]['last_error']);
		$this->assertSame('2026-11-11 10:00:00', $this->updates[0]['quarantined_at']);
	}

	public function testTheCandidateIsAttached(): void {
		$this->mapper->attachCandidate('<a@x>', 55);

		$this->assertSame([['candidate_id' => 55]], $this->updates);
	}

	public function testTheOfferClaimReportsWhetherItWon(): void {
		$offers = new OfferMapper($this->db);

		$this->assertTrue($offers->claimStatus(5, 'approved', 'sent'));
		$this->assertSame([['status' => 'sent']], $this->updates);
		$this->affected = 0;
		$this->assertFalse($offers->claimStatus(5, 'approved', 'sent'));
	}
}
