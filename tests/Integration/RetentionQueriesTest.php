<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Integration;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The retention queries for closed openings and the triage inbox against a
 * real database.
 */
class RetentionQueriesTest extends TestCase {
	private CandidateMapper $candidateMapper;
	private OpeningMapper $openingMapper;
	/** @var Candidate[] */
	private array $created = [];
	/** @var Opening[] */
	private array $openings = [];

	protected function setUp(): void {
		parent::setUp();
		$this->candidateMapper = Server::get(CandidateMapper::class);
		$this->openingMapper = Server::get(OpeningMapper::class);
	}

	protected function tearDown(): void {
		foreach ($this->created as $c) {
			try {
				$this->candidateMapper->delete($c);
			} catch (\Throwable) {
			}
		}
		foreach ($this->openings as $o) {
			try {
				$this->openingMapper->delete($o);
			} catch (\Throwable) {
			}
		}
		$this->created = [];
		$this->openings = [];
		parent::tearDown();
	}

	private function opening(string $status, ?string $closedAt): Opening {
		$o = new Opening();
		$o->setTitle('IT Opening');
		$o->setStatus($status);
		$o->setMailSlug('it-retention-' . bin2hex(random_bytes(4)));
		$o->setCreatedBy('admin');
		$o->setCreatedAt(new \DateTime('-2 years'));
		$o->setClosedAt($closedAt !== null ? new \DateTime($closedAt) : null);
		return $this->openings[] = $this->openingMapper->insert($o);
	}

	private function seed(?int $openingId, string $stage, string $createdAt = 'now', bool $pool = false): Candidate {
		$c = new Candidate();
		$c->setOpeningId($openingId);
		$c->setDisplayName('IT Candidate ' . bin2hex(random_bytes(3)));
		$c->setSource('manual');
		$c->setStage($stage);
		$c->setPoolMember($pool);
		$c->setCreatedAt(new \DateTime($createdAt));
		return $this->created[] = $this->candidateMapper->insert($c);
	}

	/**
	 * @param Candidate[] $found
	 * @param Candidate[] $mine
	 * @return int[]
	 */
	private function idsAmong(array $found, array $mine): array {
		$ids = array_map(static fn (Candidate $c) => $c->getId(), $mine);
		$hits = array_values(array_filter(array_map(static fn (Candidate $c) => $c->getId(), $found), static fn (int $id) => in_array($id, $ids, true)));
		sort($hits);
		return $hits;
	}

	public function testLeftInClosedOpenings(): void {
		$longClosed = $this->opening(Opening::STATUS_CLOSED, '-200 days');
		$recentlyClosed = $this->opening(Opening::STATUS_CLOSED, '-10 days');
		$open = $this->opening(Opening::STATUS_OPEN, null);

		$due = $this->seed($longClosed->getId(), Candidate::STAGE_SCREENING);
		$this->seed($longClosed->getId(), Candidate::STAGE_HIRED);
		$this->seed($longClosed->getId(), Candidate::STAGE_REJECTED);
		$this->seed($longClosed->getId(), Candidate::STAGE_NEW, pool: true);
		$this->seed($recentlyClosed->getId(), Candidate::STAGE_NEW);
		$this->seed($open->getId(), Candidate::STAGE_NEW);

		$found = $this->candidateMapper->findLeftInClosedOpenings(new \DateTime('-180 days'));
		$this->assertSame([$due->getId()], $this->idsAmong($found, $this->created));
	}

	public function testTriageCreatedBefore(): void {
		$opening = $this->opening(Opening::STATUS_OPEN, null);
		$old = $this->seed(null, Candidate::STAGE_NEW, '-120 days');
		$this->seed(null, Candidate::STAGE_NEW, '-10 days');
		$this->seed(null, Candidate::STAGE_NEW, '-120 days', pool: true);
		$this->seed($opening->getId(), Candidate::STAGE_NEW, '-120 days');

		$found = $this->candidateMapper->findTriageCreatedBefore(new \DateTime('-90 days'));
		$this->assertSame([$old->getId()], $this->idsAmong($found, $this->created));
	}
}
