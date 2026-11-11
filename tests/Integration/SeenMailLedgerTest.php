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
 * The Message-ID ledger with attempt tracking against a real database.
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

	public function testAttemptsCountUpWithoutMarkingTheMailIngested(): void {
		$this->assertSame(['done' => false, 'attempts' => 1, 'candidateId' => null], $this->ledger->beginAttempt($this->key));
		$this->ledger->recordError($this->key, 'first');
		$this->ledger->attachCandidate($this->key, 55);
		$this->assertSame(['done' => false, 'attempts' => 2, 'candidateId' => 55], $this->ledger->beginAttempt($this->key));
		$this->ledger->markQuarantined($this->key, 'gave up');
		$this->assertSame(['done' => false, 'attempts' => 1, 'candidateId' => 55], $this->ledger->beginAttempt($this->key));
	}

	public function testRememberAfterFailuresMarksTheMailIngested(): void {
		$this->ledger->beginAttempt($this->key);
		$this->ledger->recordError($this->key, 'transient');
		$this->ledger->remember($this->key);
		$this->assertTrue($this->ledger->beginAttempt($this->key)['done']);
	}

	public function testRememberWithoutAnAttempt(): void {
		$this->ledger->remember($this->key);
		$this->ledger->remember($this->key);
		$this->assertTrue($this->ledger->beginAttempt($this->key)['done']);
	}

	public function testAnOversizedMailIsQuarantinedWithoutAnAttempt(): void {
		$this->ledger->markQuarantined($this->key, 'The mail is larger than 30 MB');
		$this->assertSame(1, $this->ledger->beginAttempt($this->key)['attempts']);
	}
}
