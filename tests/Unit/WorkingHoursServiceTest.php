<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Service\WorkingHoursService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WorkingHoursServiceTest extends TestCase {
	private const AVAILABILITY = "BEGIN:VCALENDAR\nBEGIN:VAVAILABILITY\nBEGIN:AVAILABLE\n"
		. "DTSTART:20260817T100000Z\nDTEND:20260817T120000Z\nRRULE:FREQ=WEEKLY;BYDAY=MO\n"
		. "END:AVAILABLE\nEND:VAVAILABILITY\nEND:VCALENDAR\n";

	/** @var list<mixed> */
	private array $parameters = [];
	private int $queries = 0;

	private function service(mixed $value): WorkingHoursService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($value) {
			$this->queries++;
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $method) {
				$qb->method($method)->willReturnSelf();
			}
			$qb->method('createNamedParameter')->willReturnCallback(function ($parameter) {
				$this->parameters[] = $parameter;
				return $this->createMock(IParameter::class);
			});
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('eq')->willReturn('condition');
			$qb->method('expr')->willReturn($expr);
			$result = $this->createMock(IResult::class);
			$result->method('fetchOne')->willReturn($value);
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
		return new WorkingHoursService($db, new NullLogger());
	}

	public function testReadsTheInboxAvailabilityProperty(): void {
		$hours = $this->service(self::AVAILABILITY)->forUser('dev');
		$this->assertNotNull($hours);
		$this->assertSame(['dev', 'calendars/dev/inbox', '{urn:ietf:params:xml:ns:caldav}calendar-availability'], $this->parameters);
		$this->assertTrue($hours->contains(new \DateTimeImmutable('2026-08-24T10:00:00Z'), new \DateTimeImmutable('2026-08-24T11:00:00Z')));
	}

	public function testStreamValuesAreRead(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, self::AVAILABILITY);
		rewind($stream);
		$this->assertNotNull($this->service($stream)->forUser('dev'));
	}

	public function testNoRowMeansNotSet(): void {
		$this->assertNull($this->service(false)->forUser('dev'));
	}

	public function testResultIsCachedPerUser(): void {
		$service = $this->service(false);
		$service->forUser('dev');
		$service->forUser('dev');
		$this->assertSame(1, $this->queries);
	}

	public function testDatabaseTroubleMeansNotSet(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('no properties table'));
		$this->assertNull((new WorkingHoursService($db, new NullLogger()))->forUser('dev'));
	}
}
