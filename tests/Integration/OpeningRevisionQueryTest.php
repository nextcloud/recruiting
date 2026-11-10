<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Integration;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The board's change marker and the delete guard's count against a real
 * database: the aggregate SQL that mocked mappers cannot exercise.
 */
class OpeningRevisionQueryTest extends TestCase {
	private const OPENING = 990003;

	private CandidateMapper $candidateMapper;
	private TimelineEventMapper $eventMapper;
	/** @var Candidate[] */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		$this->candidateMapper = Server::get(CandidateMapper::class);
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
		$this->created = [];
		parent::tearDown();
	}

	private function seed(string $stage): Candidate {
		$c = new Candidate();
		$c->setOpeningId(self::OPENING);
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
		self::assertSame(0, $this->candidateMapper->countForOpening(self::OPENING));
		$this->seed(Candidate::STAGE_NEW);
		$this->seed(Candidate::STAGE_REJECTED);
		self::assertSame(2, $this->candidateMapper->countForOpening(self::OPENING));
	}

	public function testRevisionChangesWithEveryBoardChange(): void {
		$empty = $this->candidateMapper->revisionForOpening(self::OPENING);

		$a = $this->seed(Candidate::STAGE_NEW);
		$added = $this->candidateMapper->revisionForOpening(self::OPENING);
		self::assertNotSame($empty, $added);
		// Stable while nothing happens
		self::assertSame($added, $this->candidateMapper->revisionForOpening(self::OPENING));

		$this->event($a);
		$this->event($a);
		$touched = $this->candidateMapper->revisionForOpening(self::OPENING);
		self::assertNotSame($added, $touched);
		// Events must not inflate the candidate count
		self::assertStringStartsWith('1-', $touched);

		$a->setStage(Candidate::STAGE_SCREENING);
		$a->setStageChangedAt(new \DateTime('+1 minute'));
		$this->candidateMapper->update($a);
		self::assertNotSame($touched, $this->candidateMapper->revisionForOpening(self::OPENING));
	}
}
