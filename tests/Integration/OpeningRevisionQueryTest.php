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
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The board's change counter, the single-opening stage counts and the
 * delete guard's count against a real database: the SQL increments and
 * subqueries that mocked mappers cannot exercise.
 */
class OpeningRevisionQueryTest extends TestCase {
	private CandidateMapper $candidateMapper;
	private OpeningMapper $openingMapper;
	private TimelineEventMapper $eventMapper;
	/** @var Candidate[] */
	private array $created = [];
	/** @var Opening[] */
	private array $openings = [];

	protected function setUp(): void {
		parent::setUp();
		$this->candidateMapper = Server::get(CandidateMapper::class);
		$this->openingMapper = Server::get(OpeningMapper::class);
		$this->eventMapper = Server::get(TimelineEventMapper::class);
	}

	protected function tearDown(): void {
		foreach ($this->created as $c) {
			try {
				$this->eventMapper->deleteForCandidate($c->getId());
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

	private function opening(): Opening {
		$o = new Opening();
		$o->setTitle('Revision Opening');
		$o->setStatus(Opening::STATUS_OPEN);
		$o->setMailSlug('it-revision-' . bin2hex(random_bytes(4)));
		$o->setCreatedBy('admin');
		$o->setCreatedAt(new \DateTime());
		return $this->openings[] = $this->openingMapper->insert($o);
	}

	private function seed(int $openingId, string $stage): Candidate {
		$c = new Candidate();
		$c->setOpeningId($openingId);
		$c->setDisplayName('Revision Candidate ' . bin2hex(random_bytes(3)));
		$c->setEmail('r@example.org');
		$c->setSource('manual');
		$c->setStage($stage);
		$c->setCreatedAt(new \DateTime());
		return $this->created[] = $this->candidateMapper->insert($c);
	}

	private function event(Candidate $candidate): void {
		$e = new TimelineEvent();
		$e->setCandidateId($candidate->getId());
		$e->setType(TimelineEvent::TYPE_VOTE_CAST);
		$e->setCreatedAt(new \DateTime());
		$this->eventMapper->insert($e);
	}

	public function testCountCoversEveryStage(): void {
		$opening = $this->opening()->getId();
		self::assertSame(0, $this->candidateMapper->countForOpening($opening));
		$this->seed($opening, Candidate::STAGE_NEW);
		$this->seed($opening, Candidate::STAGE_REJECTED);
		self::assertSame(2, $this->candidateMapper->countForOpening($opening));
	}

	public function testStageCountsOfOneOpening(): void {
		$opening = $this->opening()->getId();
		$other = $this->opening()->getId();
		$this->seed($opening, Candidate::STAGE_NEW);
		$this->seed($opening, Candidate::STAGE_NEW);
		$this->seed($other, Candidate::STAGE_SCREENING);

		self::assertSame([$opening => [Candidate::STAGE_NEW => 2]], $this->candidateMapper->countByOpeningAndStage($opening));
	}

	public function testRevisionCountsEveryBoardChange(): void {
		$opening = $this->opening()->getId();
		$other = $this->opening()->getId();
		self::assertSame(0, $this->openingMapper->findRevision($opening));

		$a = $this->seed($opening, Candidate::STAGE_NEW);
		$added = $this->openingMapper->findRevision($opening);
		self::assertGreaterThan(0, $added);
		// Stable while nothing happens
		self::assertSame($added, $this->openingMapper->findRevision($opening));

		$this->event($a);
		$touched = $this->openingMapper->findRevision($opening);
		self::assertSame($added + 1, $touched);

		$a->setStage(Candidate::STAGE_SCREENING);
		$this->candidateMapper->update($a);
		$moved = $this->openingMapper->findRevision($opening);
		self::assertGreaterThan($touched, $moved);

		// A move to another opening changes both boards
		$otherBefore = $this->openingMapper->findRevision($other);
		$a->setOpeningId($other);
		$this->candidateMapper->update($a);
		self::assertGreaterThan($moved, $this->openingMapper->findRevision($opening));
		self::assertGreaterThan($otherBefore, $this->openingMapper->findRevision($other));
	}
}
