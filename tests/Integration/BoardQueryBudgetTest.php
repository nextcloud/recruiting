<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Integration;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Service\CandidateService;
use OCP\Diagnostics\IQueryLogger;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The board's card serialization is deliberately batch-loaded — a fixed handful
 * of queries for the whole page, never per card (spec §10.6). This guards that
 * promise: rendering N cards must cost the same number of queries as rendering
 * one, so no innocent-looking per-card lookup can creep back in. The budget
 * lives in tests/Integration/base-query-count.txt; raise it deliberately in the
 * same change if a new batched query is genuinely needed.
 */
class BoardQueryBudgetTest extends TestCase {
	private const OPENING = 990002;
	private const RECRUITER = 'itest-recruiter';

	private CandidateMapper $candidateMapper;
	private CandidateService $service;
	/** @var Candidate[] */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		$this->candidateMapper = Server::get(CandidateMapper::class);
		$this->service = Server::get(CandidateService::class);
	}

	protected function tearDown(): void {
		foreach ($this->created as $c) {
			try {
				$this->candidateMapper->delete($c);
			} catch (\Throwable) {
			}
		}
		$this->created = [];
		parent::tearDown();
	}

	private function seed(): void {
		$c = new Candidate();
		$c->setOpeningId(self::OPENING);
		$c->setDisplayName('Budget Candidate ' . bin2hex(random_bytes(3)));
		$c->setEmail('b@example.org');
		$c->setSource('manual');
		$c->setStage(Candidate::STAGE_NEW);
		$c->setCreatedAt(new \DateTime());
		$this->created[] = $this->candidateMapper->insert($c);
	}

	/**
	 * Serialize a board of the given size as a recruiter, counting queries.
	 * Uses reflection to reach the private serializeCards() — the batching is
	 * an internal contract, and calling listForOpening() would drag in
	 * permission lookups that are not what this guards.
	 */
	private function queriesToSerialize(int $cards): int {
		for ($i = 0; $i < $cards; $i++) {
			$this->seed();
		}
		$candidates = $this->candidateMapper->findForOpening(self::OPENING, Candidate::STAGE_NEW, 200, 0);

		$logger = Server::get(IQueryLogger::class);
		$logger->activate();
		$before = count($logger->getQueries());

		$method = new \ReflectionMethod(CandidateService::class, 'serializeCards');
		$method->invoke($this->service, $candidates, self::RECRUITER);

		return count($logger->getQueries()) - $before;
	}

	public function testCardSerializationCostIsFlatInCardCount(): void {
		$budget = (int)trim((string)file_get_contents(__DIR__ . '/base-query-count.txt'));

		$oneCard = $this->queriesToSerialize(1);
		if ($oneCard === 0) {
			self::markTestSkipped('Query logger inactive on this instance.');
		}
		// Clean up between the two runs so the second is a fresh page.
		$this->tearDown();

		$manyCards = $this->queriesToSerialize(25);

		self::assertLessThanOrEqual(
			$budget,
			$manyCards,
			"Serializing 25 cards used $manyCards queries; the budget is $budget. "
			. 'A per-card query has crept in — batch it, or raise base-query-count.txt deliberately.',
		);
		// The whole point: 25 cards cost no more than 1.
		self::assertSame(
			$oneCard,
			$manyCards,
			"1 card cost $oneCard queries, 25 cost $manyCards — the board no longer scales flatly.",
		);
	}
}
