<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Document;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\CandidateStatus;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\ReferralService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReferralServiceTest extends TestCase {
	private ConfigService&MockObject $config;
	private OpeningMapper&MockObject $openingMapper;
	private CandidateMapper&MockObject $candidateMapper;
	private CandidateService&MockObject $candidates;
	private DocumentService&MockObject $documents;
	private TimelineService&MockObject $timeline;
	private ReferralService $service;

	protected function setUp(): void {
		$this->config = $this->createMock(ConfigService::class);
		$this->config->method('isReferralsEnabled')->willReturn(true);
		$this->openingMapper = $this->createMock(OpeningMapper::class);
		$this->openingMapper->method('find')->willReturnCallback(function (int $id): Opening {
			$statuses = [1 => Opening::STATUS_OPEN, 2 => Opening::STATUS_ON_HOLD, 3 => Opening::STATUS_CLOSED];
			if (!isset($statuses[$id])) {
				throw new DoesNotExistException('');
			}
			return $this->opening($id, $statuses[$id]);
		});
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->candidates = $this->createMock(CandidateService::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$this->service = new ReferralService($this->config, $this->openingMapper, $this->candidateMapper, $this->candidates, $this->documents, $this->timeline, $l10n);
	}

	private function opening(int $id, string $status): Opening {
		$opening = new Opening();
		$opening->setId($id);
		$opening->setTitle('Opening ' . $id);
		$opening->setStatus($status);
		return $opening;
	}

	private function valid(array $overrides = []): array {
		return array_merge([
			'openingId' => '1',
			'name' => 'Grace Hopper',
			'email' => 'grace@example.org',
			'phone' => '+1 555 0100',
			'note' => 'Brilliant compiler engineer, we worked together.',
			'consent' => '1',
		], $overrides);
	}

	private function created(): Candidate {
		$candidate = new Candidate();
		$candidate->setId(42);
		$candidate->setDisplayName('Grace Hopper');
		$candidate->setStage(Candidate::STAGE_NEW);
		$candidate->setCreatedAt(new \DateTime('2026-11-10T09:00:00Z'));
		return $candidate;
	}

	public function testOnlyOpenOpeningsAreOffered(): void {
		$this->openingMapper->method('findAll')->willReturn([
			$this->opening(1, Opening::STATUS_OPEN),
			$this->opening(2, Opening::STATUS_ON_HOLD),
			$this->opening(3, Opening::STATUS_CLOSED),
		]);
		$this->assertSame([['id' => 1, 'title' => 'Opening 1']], $this->service->openings());
	}

	public function testReferralCreatesACandidateWithSourceAndReferrer(): void {
		$candidate = $this->created();
		$this->candidates->expects($this->once())->method('insertCandidate')
			->with(1, 'Grace Hopper', 'grace@example.org', '+1 555 0100', Candidate::SOURCE_REFERRAL, 'bob', 'bob')
			->willReturn($candidate);
		$this->timeline->expects($this->once())->method('record')
			->with(42, TimelineEvent::TYPE_REFERRAL_NOTE, 'bob', ['note' => 'Brilliant compiler engineer, we worked together.']);
		$this->documents->expects($this->never())->method('add');

		$result = $this->service->create('bob', $this->valid(), null);
		$this->assertSame(CandidateStatus::RECEIVED, $result['status']);
		$this->assertSame('Opening 1', $result['openingTitle']);
	}

	public function testTheCvBecomesADocument(): void {
		$candidate = $this->created();
		$this->candidates->method('insertCandidate')->willReturn($candidate);
		$this->documents->expects($this->once())->method('validate')->with('application/pdf', '%PDF')->willReturn('application/pdf');
		$document = new Document();
		$document->setName('cv.pdf');
		$this->documents->expects($this->once())->method('add')->with($candidate, 'cv.pdf', 'application/pdf', '%PDF', 'bob')->willReturn($document);
		$this->timeline->expects($this->exactly(2))->method('record');
		$this->service->create('bob', $this->valid(), ['name' => 'cv.pdf', 'mime' => 'application/pdf', 'content' => '%PDF']);
	}

	public function testAnInvalidCvStopsTheReferralBeforeAnythingIsCreated(): void {
		$this->documents->method('validate')->willThrowException(new ValidationException('This file type is not allowed'));
		$this->candidates->expects($this->never())->method('insertCandidate');
		$this->expectException(ValidationException::class);
		$this->service->create('bob', $this->valid(), ['name' => 'x.exe', 'mime' => 'application/x-msdownload', 'content' => 'MZ']);
	}

	/**
	 * @return array<string, array{0: array}>
	 */
	public static function invalidInput(): array {
		return [
			'opening on hold' => [['openingId' => '2']],
			'closed opening' => [['openingId' => '3']],
			'unknown opening' => [['openingId' => '99']],
			'no name' => [['name' => '  ']],
			'bad email' => [['email' => 'not-an-email']],
			'bad phone' => [['phone' => 'call me']],
			'no note' => [['note' => '']],
			'note too long' => [['note' => str_repeat('x', ReferralService::NOTE_LIMIT + 1)]],
			'no consent' => [['consent' => '0']],
			'missing consent' => [['consent' => null]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
	public function testInvalidReferralsAreRejected(array $overrides): void {
		$this->candidates->expects($this->never())->method('insertCandidate');
		$this->expectException(ValidationException::class);
		$this->service->create('bob', $this->valid($overrides), null);
	}

	public function testReferralsCanBeTurnedOff(): void {
		$config = $this->createMock(ConfigService::class);
		$config->method('isReferralsEnabled')->willReturn(false);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$service = new ReferralService($config, $this->openingMapper, $this->candidateMapper, $this->candidates, $this->documents, $this->timeline, $l10n);
		$this->candidates->expects($this->never())->method('insertCandidate');
		$this->expectException(NotPermittedException::class);
		$service->create('bob', $this->valid(), null);
	}

	public function testOwnReferralsShowTheCoarseStatusOnly(): void {
		$rejected = $this->created();
		$rejected->setStage(Candidate::STAGE_REJECTED);
		$rejected->setRejectionReason('not_qualified');
		$rejected->setOpeningId(1);
		$this->candidateMapper->expects($this->once())->method('findByReferrer')->with('bob')->willReturn([$rejected]);
		$this->openingMapper->method('findTitle')->willReturn('Opening 1');

		$list = $this->service->listMine('bob');
		$this->assertSame([[
			'id' => 42,
			'name' => 'Grace Hopper',
			'openingTitle' => 'Opening 1',
			'status' => CandidateStatus::CLOSED,
			'createdAt' => '2026-11-10T09:00:00+00:00',
		]], $list);
	}
}
