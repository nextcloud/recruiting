<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\PoolService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class PoolServiceTest extends TestCase {
	private CandidateMapper $candidateMapper;
	private PoolService $service;

	protected function setUp(): void {
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('a', 48));
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params) => 'https://cloud.example/pool/' . $params['token'],
		);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-08-16T12:00:00+00:00'));

		$this->service = new PoolService(
			$this->candidateMapper,
			$this->createMock(OpeningMapper::class),
			$this->createMock(PermissionService::class),
			$this->createMock(TimelineService::class),
			$urlGenerator,
			$random,
			$timeFactory,
		);
	}

	public function testConsentUrlCreatesTokenOnce(): void {
		$candidate = new Candidate();
		$candidate->setId(5);
		$this->candidateMapper->expects($this->once())->method('update');

		$url = $this->service->prepareConsentUrl($candidate, 'hr');
		$this->assertSame('https://cloud.example/pool/' . str_repeat('a', 48), $url);
		// second call reuses the token, no second update
		$this->assertSame($url, $this->service->prepareConsentUrl($candidate, 'hr'));
	}

	public function testConfirmSetsMembership(): void {
		$candidate = new Candidate();
		$candidate->setId(5);
		$this->candidateMapper->expects($this->once())->method('update');

		$this->service->confirm($candidate);
		$this->assertTrue($candidate->getPoolMember());
		$this->assertNotNull($candidate->getPoolConsentAt());

		// idempotent: confirming again changes nothing
		$this->service->confirm($candidate);
	}

	public function testTokenLookupRejectsGarbage(): void {
		$this->candidateMapper->expects($this->never())->method('findByPoolToken');
		$this->assertNull($this->service->findByToken('short'));
		$this->assertNull($this->service->findByToken(str_repeat('a', 30) . '../../etc'));
	}

	public function testAnonymizedCandidatesAreInvisible(): void {
		$candidate = new Candidate();
		$candidate->setId(5);
		$candidate->setAnonymizedAt(new \DateTime());
		$this->candidateMapper->method('findByPoolToken')->willReturn($candidate);
		$this->assertNull($this->service->findByToken(str_repeat('b', 48)));
	}
}
