<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\CandidateScope;
use OCA\Recruiting\Service\ReportCache;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The query builder is replaced by a recorder that renders every condition
 * as readable pseudo-SQL, so the tests can check which rows a query
 * restricts to without a database.
 */
class CandidateMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private ReportCache&MockObject $reportCache;
	private CandidateMapper $mapper;

	/** @var list<string> conditions passed to where()/andWhere() of the main query */
	private array $conditions = [];
	/** @var array<string, mixed> */
	private array $calls = [];
	private int $builders = 0;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('escapeLikeParameter')->willReturnArgument(0);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder($this->builders++ === 0));
		$this->reportCache = $this->createMock(ReportCache::class);
		$this->mapper = new CandidateMapper($this->db, $this->reportCache);
	}

	private function recordingBuilder(bool $main): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'orderBy', 'addOrderBy', 'delete', 'update', 'insert', 'set', 'setValue'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$tables = [];
		$qb->method('from')->willReturnCallback(function (string $table, ?string $alias = null) use ($qb, &$tables) {
			$tables[] = $table;
			return $qb;
		});
		$qb->method('innerJoin')->willReturnCallback(function (string $from, string $table) use ($qb, &$tables) {
			$tables[] = $table;
			return $qb;
		});
		$where = [];
		$record = function (...$conditions) use ($qb, $main, &$where) {
			foreach ($conditions as $condition) {
				$where[] = (string)$condition;
				if ($main) {
					$this->conditions[] = (string)$condition;
				}
			}
			return $qb;
		};
		$qb->method('where')->willReturnCallback($record);
		$qb->method('andWhere')->willReturnCallback($record);
		$qb->method('setMaxResults')->willReturnCallback(function ($max) use ($qb) {
			$this->calls['max'] = $max;
			return $qb;
		});
		$qb->method('setFirstResult')->willReturnCallback(function ($first) use ($qb) {
			$this->calls['first'] = $first;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(fn ($value) => $this->stringable(IParameter::class, json_encode($value)));
		$qb->method('createFunction')->willReturnCallback(fn (string $sql) => $this->stringable(IQueryFunction::class, '(' . $sql . ')'));
		$qb->method('getSQL')->willReturnCallback(static function () use (&$tables, &$where) {
			return 'SELECT FROM ' . implode(' JOIN ', $tables) . ' WHERE ' . implode(' AND ', $where);
		});
		$qb->method('expr')->willReturn($this->expressions());
		$functions = $this->createMock(IFunctionBuilder::class);
		$functions->method('lower')->willReturnCallback(fn (string $field) => $this->stringable(IQueryFunction::class, 'LOWER(' . $field . ')'));
		$qb->method('func')->willReturn($functions);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	private function expressions(): IExpressionBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$binary = static fn (string $op) => static fn ($x, $y) => $x . ' ' . $op . ' ' . $y;
		$expr->method('eq')->willReturnCallback($binary('='));
		$expr->method('neq')->willReturnCallback($binary('<>'));
		$expr->method('in')->willReturnCallback($binary('IN'));
		$expr->method('iLike')->willReturnCallback($binary('ILIKE'));
		$expr->method('gte')->willReturnCallback($binary('>='));
		$expr->method('isNull')->willReturnCallback(static fn ($x) => $x . ' IS NULL');
		$expr->method('isNotNull')->willReturnCallback(static fn ($x) => $x . ' IS NOT NULL');
		$expr->method('andX')->willReturnCallback(fn (...$parts) => $this->composite('AND', $parts));
		$expr->method('orX')->willReturnCallback(fn (...$parts) => $this->composite('OR', $parts));
		return $expr;
	}

	private function composite(string $type, array $parts): ICompositeExpression {
		return new class($type, $parts) implements ICompositeExpression {
			public function __construct(
				private string $type,
				private array $parts,
			) {
			}

			public function addMultiple(array $parts = []): ICompositeExpression {
				$this->parts = array_merge($this->parts, $parts);
				return $this;
			}

			public function add($part): ICompositeExpression {
				$this->parts[] = $part;
				return $this;
			}

			public function count(): int {
				return count($this->parts);
			}

			public function getType(): string {
				return $this->type;
			}

			public function __toString(): string {
				return '(' . implode(' ' . $this->type . ' ', array_map('strval', $this->parts)) . ')';
			}
		};
	}

	/**
	 * @template T of object
	 * @param class-string<T> $interface
	 * @return T
	 */
	private function stringable(string $interface, string $text): object {
		$mock = $this->createMock($interface);
		$mock->method('__toString')->willReturn($text);
		return $mock;
	}

	private function candidate(?int $openingId): Candidate {
		$candidate = new Candidate();
		$candidate->setId(7);
		$candidate->setOpeningId($openingId);
		$candidate->resetUpdatedFields();
		return $candidate;
	}

	public function testRecruiterSearchIsUnscopedButSkipsAnonymized(): void {
		$this->mapper->search('Jane', new CandidateScope('hr', true), 11, 0);

		$this->assertSame([
			'(c.display_name ILIKE "%jane%" OR c.email ILIKE "%jane%")',
			'c.anonymized_at IS NULL',
		], $this->conditions);
		$this->assertSame(11, $this->calls['max']);
		$this->assertSame(0, $this->calls['first']);
	}

	public function testSearchAppliesTheRoleScopeInSql(): void {
		$this->mapper->search('jane', new CandidateScope('alice', false, [3, 4], [7]), 6, 12);

		$this->assertCount(3, $this->conditions);
		$this->assertSame('c.anonymized_at IS NULL', $this->conditions[1]);
		$scope = $this->conditions[2];
		// managed/observed openings: every candidate
		$this->assertStringStartsWith('(c.opening_id IN [3,4] OR ', $scope);
		// interviewed opening: only assigned candidates or interview attendance
		$this->assertStringContainsString(
			'(c.opening_id IN [7] AND (c.id IN (SELECT FROM recruiting_assignments WHERE sa.uid = "alice")'
			. ' OR c.id IN (SELECT FROM recruiting_interviews JOIN recruiting_intv_attendees WHERE sat.uid = "alice")))',
			$scope,
		);
		// offer approvers, outside the triage inbox
		$this->assertStringEndsWith(
			'(c.opening_id IS NOT NULL AND c.id IN (SELECT FROM recruiting_offers WHERE so.approver_uid = "alice")))',
			$scope,
		);
		$this->assertSame(12, $this->calls['first']);
	}

	public function testUserWithoutRolesOnlyFindsOffersToApprove(): void {
		$this->mapper->search('jane', new CandidateScope('bob', false), 5);

		$this->assertSame(
			'((c.opening_id IS NOT NULL AND c.id IN (SELECT FROM recruiting_offers WHERE so.approver_uid = "bob")))',
			$this->conditions[2],
		);
	}

	public function testDuplicateDetectionComparesEmailInLowerCase(): void {
		$this->mapper->findRecentByEmail(' Jane.Doe@Example.COM ', 3, new \DateTime('2026-08-01'));
		$this->assertSame('LOWER(email) = "jane.doe@example.com"', $this->conditions[0]);
	}

	public function testOtherApplicationsCompareEmailInLowerCaseAndSkipAnonymized(): void {
		$this->mapper->findOtherApplications('Jane@Example.com', 7);
		$this->assertSame([
			'LOWER(email) = "jane@example.com"',
			'id <> 7',
			'anonymized_at IS NULL',
		], $this->conditions);
	}

	public function testOtherApplicationsWithoutEmailQueryNothing(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->assertSame([], $this->mapper->findOtherApplications('', 7));
	}

	public function testUpdateInvalidatesTheOpeningReports(): void {
		$candidate = $this->candidate(5);
		$candidate->setStage(Candidate::STAGE_SCREENING);
		$this->reportCache->expects($this->once())->method('invalidate')->with(5);
		$this->mapper->update($candidate);
	}

	public function testMovingACandidateInvalidatesAllReports(): void {
		$candidate = $this->candidate(null);
		$candidate->setOpeningId(5);
		$this->reportCache->expects($this->once())->method('invalidate')->with(null);
		$this->mapper->update($candidate);
	}

	public function testTriageCandidatesInvalidateNothing(): void {
		$candidate = $this->candidate(null);
		$candidate->setStage(Candidate::STAGE_SCREENING);
		$this->reportCache->expects($this->never())->method('invalidate');
		$this->mapper->update($candidate);
	}

	public function testInsertAndDeleteInvalidateTheOpeningReports(): void {
		$this->reportCache->expects($this->exactly(2))->method('invalidate')->with(9);
		$candidate = new Candidate();
		$candidate->setOpeningId(9);
		$candidate->setDisplayName('Jane');
		$this->mapper->insert($candidate);
		$this->mapper->delete($this->candidate(9));
	}
}
