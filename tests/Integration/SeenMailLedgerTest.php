<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Integration;

use OCA\Recruiting\Db\SeenMailMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The Message-ID ledger with failure tracking against a real database.
 */
class SeenMailLedgerTest extends TestCase {
	private SeenMailMapper $ledger;
	private string $key;

	protected function setUp(): void {
		parent::setUp();
		$this->ledger = Server::get(SeenMailMapper::class);
		$this->key = '<it-' . bin2hex(random_bytes(6)) . '@example.org>';
	}

	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('recruiting_mail_seen')
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($this->key)))
			->executeStatement();
		parent::tearDown();
	}

	public function testFailuresCountUpWithoutMarkingTheMailIngested(): void {
		$this->assertSame(1, $this->ledger->recordFailure($this->key, 'first'));
		$this->assertSame(2, $this->ledger->recordFailure($this->key, 'second'));
		$this->assertFalse($this->ledger->hasSeen($this->key));
		$this->ledger->markQuarantined($this->key);
		$this->assertFalse($this->ledger->hasSeen($this->key));
	}

	public function testRememberAfterFailuresMarksTheMailIngested(): void {
		$this->ledger->recordFailure($this->key, 'transient');
		$this->ledger->remember($this->key);
		$this->assertTrue($this->ledger->hasSeen($this->key));
	}

	public function testRememberWithoutFailures(): void {
		$this->assertFalse($this->ledger->hasSeen($this->key));
		$this->ledger->remember($this->key);
		$this->ledger->remember($this->key);
		$this->assertTrue($this->ledger->hasSeen($this->key));
	}
}
