<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Migration\StripActivityCandidateNames;
use OCA\Recruiting\Service\ActivityPurgeService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OCP cannot delete activities, so the app removes its own rows from the
 * activity app's tables — when they exist, and without ever breaking the
 * deletion that asked for it.
 */
class ActivityPurgeServiceTest extends TestCase {
	/** @var string[] tables the activity app has on this instance */
	private array $tables = [];
	/** @var list<array{op: string, table: string, conditions: list<array{0: string, 1: mixed}>, set: array<string, mixed>}> */
	private array $statements = [];
	/** @var array<string, list<array<string, mixed>>> rows returned per table by select queries */
	private array $selectRows = [];

	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(fn (string $table) => in_array($table, $this->tables, true));
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
		return $db;
	}

	private function queryBuilder(): IQueryBuilder {
		$state = ['op' => '', 'table' => '', 'conditions' => [], 'set' => []];
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'gt', 'like'] as $operator) {
			$expr->method($operator)->willReturnCallback(static fn (string $column, $value) => json_encode([$column, $value]));
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('delete')->willReturnCallback(function (string $table) use ($qb, &$state) {
			$state['op'] = 'delete';
			$state['table'] = $table;
			return $qb;
		});
		$qb->method('update')->willReturnCallback(function (string $table) use ($qb, &$state) {
			$state['op'] = 'update';
			$state['table'] = $table;
			return $qb;
		});
		$qb->method('select')->willReturnCallback(function () use ($qb, &$state) {
			$state['op'] = 'select';
			return $qb;
		});
		$qb->method('from')->willReturnCallback(function (string $table) use ($qb, &$state) {
			$state['table'] = $table;
			return $qb;
		});
		$qb->method('set')->willReturnCallback(function (string $column, $value) use ($qb, &$state) {
			$state['set'][$column] = $value;
			return $qb;
		});
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $condition) use ($qb, &$state) {
				$state['conditions'][] = json_decode($condition, true);
				return $qb;
			});
		}
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('executeStatement')->willReturnCallback(function () use (&$state) {
			$this->statements[] = $state;
			return 1;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$state) {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAllAssociative')->willReturn($this->selectRows[$state['table']] ?? []);
			return $result;
		});
		return $qb;
	}

	public function testNothingHappensWithoutTheActivityApp(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$db->expects($this->never())->method('getQueryBuilder');

		$service = new ActivityPurgeService($db, new NullLogger());
		$service->purgeForCandidate(7);
		$this->assertSame(0, $service->stripStoredNames());
	}

	public function testPurgeDeletesTheCandidatesRowsFromBothTables(): void {
		$this->tables = ['activity', 'activity_mq'];
		(new ActivityPurgeService($this->connection(), new NullLogger()))->purgeForCandidate(7);

		$this->assertCount(2, $this->statements);
		[$activity, $queue] = $this->statements;
		$this->assertSame(['delete', 'activity'], [$activity['op'], $activity['table']]);
		$this->assertSame([['app', 'recruiting'], ['object_type', 'candidate'], ['object_id', 7]], $activity['conditions']);
		$this->assertSame(['delete', 'activity_mq'], [$queue['op'], $queue['table']]);
		$this->assertSame([['amq_appid', 'recruiting'], ['object_type', 'candidate'], ['object_id', 7]], $queue['conditions']);
	}

	public function testPurgeSkipsAMissingMailQueue(): void {
		$this->tables = ['activity'];
		(new ActivityPurgeService($this->connection(), new NullLogger()))->purgeForCandidate(7);
		$this->assertSame(['activity'], array_column($this->statements, 'table'));
	}

	public function testPurgeFailureIsLoggedNotThrown(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willThrowException(new \RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new ActivityPurgeService($db, $logger))->purgeForCandidate(7);
	}

	public function testWithoutNameRemovesOnlyTheName(): void {
		$this->assertSame(
			'{"toStage":"screening","candidateId":7,"openingId":3}',
			ActivityPurgeService::withoutName('{"toStage":"screening","candidate":"Jane Doe","candidateId":7,"openingId":3}'),
		);
		$this->assertNull(ActivityPurgeService::withoutName('{"candidateId":7}'));
		$this->assertNull(ActivityPurgeService::withoutName('not json'));
	}

	public function testStripRewritesStoredNames(): void {
		$this->tables = ['activity'];
		$this->selectRows['activity'] = [
			['activity_id' => 11, 'subjectparams' => '{"candidate":"Jane Doe","candidateId":5}'],
			['activity_id' => 12, 'subjectparams' => '{"candidateId":6,"title":"\"candidate\": in a title"}'],
		];

		$rewritten = (new ActivityPurgeService($this->connection(), new NullLogger()))->stripStoredNames();

		$this->assertSame(1, $rewritten);
		$updates = array_values(array_filter($this->statements, static fn ($s) => $s['op'] === 'update'));
		$this->assertCount(1, $updates);
		$this->assertSame('activity', $updates[0]['table']);
		$this->assertSame(['subjectparams' => '{"candidateId":5}'], $updates[0]['set']);
		$this->assertSame([['activity_id', 11]], $updates[0]['conditions']);
	}

	public function testRepairStepRunsOnce(): void {
		$purge = $this->createMock(ActivityPurgeService::class);
		$purge->expects($this->once())->method('stripStoredNames')->willReturn(3);
		$done = false;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(static function () use (&$done) {
			return $done;
		});
		$config->method('setValueBool')->willReturnCallback(static function (string $app, string $key, bool $value) use (&$done) {
			$done = $value;
			return true;
		});

		$step = new StripActivityCandidateNames($purge, $config);
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));
		$this->assertTrue($done);
	}
}
