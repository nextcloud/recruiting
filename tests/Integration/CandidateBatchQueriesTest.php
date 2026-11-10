<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Integration;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Vote;
use OCA\Recruiting\Db\VoteMapper;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The batch card queries and the list pagination against a real database —
 * the hand-written SQL that unit tests with mocked mappers cannot exercise.
 */
class CandidateBatchQueriesTest extends TestCase {
	private const OPENING = 990001;

	private CandidateMapper $candidateMapper;
	private VoteMapper $voteMapper;
	/** @var Candidate[] */
	private array $created = [];
	/** @var Vote[] */
	private array $createdVotes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->candidateMapper = Server::get(CandidateMapper::class);
		$this->voteMapper = Server::get(VoteMapper::class);
	}

	protected function tearDown(): void {
		foreach ($this->createdVotes as $v) {
			try {
				$this->voteMapper->delete($v);
			} catch (\Throwable) {
			}
		}
		foreach ($this->created as $c) {
			try {
				$this->candidateMapper->delete($c);
			} catch (\Throwable) {
			}
		}
		$this->created = [];
		$this->createdVotes = [];
		parent::tearDown();
	}

	private function seed(string $stage, ?int $opening = self::OPENING): Candidate {
		$c = new Candidate();
		$c->setOpeningId($opening);
		$c->setDisplayName('IT Candidate ' . bin2hex(random_bytes(3)));
		$c->setEmail('it@example.org');
		$c->setSource('manual');
		$c->setStage($stage);
		$c->setCreatedAt(new \DateTime());
		return $this->created[] = $this->candidateMapper->insert($c);
	}

	private function vote(int $candidateId, string $uid, string $vote): void {
		$v = new Vote();
		$v->setCandidateId($candidateId);
		$v->setUid($uid);
		$v->setVote($vote);
		$v->setCreatedAt(new \DateTime());
		$v->setUpdatedAt(new \DateTime());
		$this->createdVotes[] = $this->voteMapper->insert($v);
	}

	public function testTallyForGroupsByCandidateAndVote(): void {
		$a = $this->seed(Candidate::STAGE_SCREENING);
		$b = $this->seed(Candidate::STAGE_SCREENING);
		$this->vote($a->getId(), 'itu1', Vote::YES);
		$this->vote($a->getId(), 'itu2', Vote::YES);
		$this->vote($a->getId(), 'itu3', Vote::NO);
		$this->vote($b->getId(), 'itu1', Vote::MAYBE);

		$tally = $this->voteMapper->tallyFor([$a->getId(), $b->getId()]);

		self::assertSame(2, $tally[$a->getId()][Vote::YES]);
		self::assertSame(1, $tally[$a->getId()][Vote::NO]);
		self::assertSame(1, $tally[$b->getId()][Vote::MAYBE]);
		self::assertArrayNotHasKey(Vote::YES, $tally[$b->getId()] ?? []);
	}

	public function testVotedUidsForReturnsWhoVotedPerCandidate(): void {
		$a = $this->seed(Candidate::STAGE_SCREENING);
		$this->vote($a->getId(), 'itu1', Vote::YES);
		$this->vote($a->getId(), 'itu2', Vote::NO);

		$voted = $this->voteMapper->votedUidsFor([$a->getId()]);

		sort($voted[$a->getId()]);
		self::assertSame(['itu1', 'itu2'], $voted[$a->getId()]);
	}

	public function testFindForOpeningPagesWithinAStage(): void {
		for ($i = 0; $i < 5; $i++) {
			$this->seed(Candidate::STAGE_NEW);
		}
		$firstTwo = $this->candidateMapper->findForOpening(self::OPENING, Candidate::STAGE_NEW, 2, 0);
		$nextTwo = $this->candidateMapper->findForOpening(self::OPENING, Candidate::STAGE_NEW, 2, 2);

		self::assertCount(2, $firstTwo);
		self::assertCount(2, $nextTwo);
		// Pages do not overlap.
		$ids = array_merge(
			array_map(static fn (Candidate $c) => $c->getId(), $firstTwo),
			array_map(static fn (Candidate $c) => $c->getId(), $nextTwo),
		);
		self::assertCount(4, array_unique($ids));
	}

	public function testTriagePaginationBoundsResults(): void {
		for ($i = 0; $i < 3; $i++) {
			$this->seed(Candidate::STAGE_NEW, null); // opening null = triage inbox
		}
		$page = $this->candidateMapper->findTriage(2, 0);
		self::assertCount(2, $page);
	}
}
