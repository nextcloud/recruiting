<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\ReportMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ReportMapperTest extends TestCase {
	/** @var list<string> */
	private array $selected = [];

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function mapper(array $rows): ReportMapper {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['from', 'innerJoin', 'where', 'andWhere', 'groupBy', 'orderBy', 'addOrderBy', 'addSelect'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('select')->willReturnCallback(function (...$columns) use ($qb) {
			$this->selected = array_map('strval', $columns);
			return $qb;
		});
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$functions = $this->createMock(IFunctionBuilder::class);
		$functions->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('func')->willReturn($functions);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return new ReportMapper($db);
	}

	private function window(): array {
		return [new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), new \DateTimeImmutable('2026-02-01T00:00:00+00:00')];
	}

	public function testCountByGroupsPerOpeningAndSkipsEmptyValues(): void {
		$mapper = $this->mapper([
			['opening_id' => '1', 'source' => 'web', 'cnt' => '4'],
			['opening_id' => '1', 'source' => '', 'cnt' => '2'],
			['opening_id' => '2', 'source' => 'email', 'cnt' => '1'],
		]);
		$this->assertSame([1 => ['web' => 4], 2 => ['email' => 1]], $mapper->countBy('source', [1, 2], ...$this->window()));
		$this->assertSame(['opening_id', 'source'], $this->selected);
	}

	public function testCountByRefusesOtherColumns(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->mapper([])->countBy('email', [1], ...$this->window());
	}

	public function testNoOpeningsMeansNoQuery(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$mapper = new ReportMapper($db);
		$this->assertSame([], $mapper->applications([], ...$this->window()));
		$this->assertSame([], $mapper->stageChanges([], ...$this->window()));
	}

	public function testApplicationsReadOnlyTimingColumns(): void {
		$mapper = $this->mapper([
			['id' => '10', 'opening_id' => '1', 'stage' => 'hired', 'created_at' => '2026-01-05 00:00:00', 'hired_at' => '2026-01-15 12:00:00'],
			['id' => '11', 'opening_id' => '1', 'stage' => 'new', 'created_at' => '2026-01-06 00:00:00', 'hired_at' => null],
		]);
		$rows = $mapper->applications([1], ...$this->window());
		$this->assertSame(['id', 'opening_id', 'stage', 'created_at', 'hired_at'], $this->selected);
		$this->assertSame([1 => [
			['id' => 10, 'stage' => 'hired', 'createdAt' => 1767571200, 'hiredAt' => 1768478400],
			['id' => 11, 'stage' => 'new', 'createdAt' => 1767657600, 'hiredAt' => null],
		]], $rows);
	}

	public function testStageChangesSkipEventsOfMergedDuplicates(): void {
		$mapper = $this->mapper([
			['candidate_id' => '10', 'created_at' => '2026-01-07 00:00:00', 'data' => '{"from":"new","to":"screening"}'],
			['candidate_id' => '10', 'created_at' => '2026-01-08 00:00:00', 'data' => '{"from":"new","to":"offer","mergedFrom":99}'],
			['candidate_id' => '11', 'created_at' => '2026-01-09 00:00:00', 'data' => null],
		]);
		$this->assertSame(
			[10 => [['at' => 1767744000, 'from' => 'new', 'to' => 'screening']]],
			$mapper->stageChanges([1], ...$this->window()),
		);
	}
}
