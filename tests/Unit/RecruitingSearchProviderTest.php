<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\CandidateScope;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Search\RecruitingSearchProvider;
use OCA\Recruiting\Service\PermissionService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RecruitingSearchProviderTest extends TestCase {
	private CandidateMapper&MockObject $candidateMapper;
	private OpeningMapper&MockObject $openingMapper;
	private DocumentMapper&MockObject $documentMapper;
	private PermissionService&MockObject $permissions;
	private RecruitingSearchProvider $provider;

	protected function setUp(): void {
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->openingMapper = $this->createMock(OpeningMapper::class);
		$this->documentMapper = $this->createMock(DocumentMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud/apps/recruiting/');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->provider = new RecruitingSearchProvider($this->candidateMapper, $this->openingMapper, $this->documentMapper, $this->permissions, $urlGenerator, $l10n);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function query(string $term, int $limit, int|string|null $cursor = null): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);
		return $query;
	}

	private function candidate(int $id): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setOpeningId(1);
		$candidate->setDisplayName('Jane ' . $id);
		$candidate->setEmail('jane' . $id . '@example.com');
		return $candidate;
	}

	private function opening(int $id): Opening {
		$opening = new Opening();
		$opening->setId($id);
		$opening->setTitle('Java developer');
		return $opening;
	}

	public function testFirstPageScopesOpeningsAndCandidatesInSql(): void {
		$scope = new CandidateScope('alice', false, [1], [2]);
		$this->permissions->method('candidateScope')->with('alice')->willReturn($scope);
		$this->permissions->method('canViewOpening')->willReturn(true);
		$this->permissions->method('canViewCandidate')->willReturn(true);
		$this->openingMapper->expects($this->once())->method('searchByTitle')
			->with('ja', [1, 2], 3)
			->willReturn([$this->opening(1)]);
		// one opening on the page leaves four slots; one more row means "more"
		$this->candidateMapper->expects($this->once())->method('search')
			->with('ja', $scope, 5, 0)
			->willReturn(array_map($this->candidate(...), [10, 11, 12, 13, 14]));

		$result = $this->provider->search($this->user('alice'), $this->query(' ja ', 5))->jsonSerialize();

		$this->assertTrue($result['isPaginated']);
		$this->assertSame(4, $result['cursor']);
		$this->assertCount(5, $result['entries']);
		$titles = array_map(static fn ($entry) => $entry->jsonSerialize()['title'], $result['entries']);
		$this->assertSame(['Java developer', 'Jane 10', 'Jane 11', 'Jane 12', 'Jane 13'], $titles);
	}

	public function testFollowingPagesSkipOpeningsAndContinueAtTheCursor(): void {
		$this->permissions->method('candidateScope')->willReturn(new CandidateScope('hr', true));
		$this->permissions->method('canViewCandidate')->willReturn(true);
		$this->openingMapper->expects($this->never())->method('searchByTitle');
		$this->candidateMapper->expects($this->once())->method('search')
			->with('ja', $this->anything(), 6, 4)
			->willReturn([$this->candidate(15)]);

		$result = $this->provider->search($this->user('hr'), $this->query('ja', 5, '4'))->jsonSerialize();

		$this->assertFalse($result['isPaginated']);
		$this->assertCount(1, $result['entries']);
	}

	public function testRecruitersSearchAllOpenings(): void {
		$this->permissions->method('candidateScope')->willReturn(new CandidateScope('hr', true));
		$this->openingMapper->expects($this->once())->method('searchByTitle')->with('ja', null, 3)->willReturn([]);
		$this->provider->search($this->user('hr'), $this->query('ja', 10));
	}

	public function testVisibilityIsStillCheckedOnTheReturnedPage(): void {
		$this->permissions->method('candidateScope')->willReturn(new CandidateScope('alice', false, [1]));
		$this->permissions->method('canViewCandidate')
			->willReturnCallback(static fn (string $uid, Candidate $c) => $c->getId() !== 11);
		$this->candidateMapper->method('search')->willReturn([$this->candidate(10), $this->candidate(11)]);

		$result = $this->provider->search($this->user('alice'), $this->query('ja', 10, '0'))->jsonSerialize();

		$this->assertCount(1, $result['entries']);
		$this->assertSame('Jane 10', $result['entries'][0]->jsonSerialize()['title']);
	}

	public function testEmptyTermSearchesNothing(): void {
		$this->candidateMapper->expects($this->never())->method('search');
		$this->openingMapper->expects($this->never())->method('searchByTitle');
		$result = $this->provider->search($this->user('hr'), $this->query('  ', 10))->jsonSerialize();
		$this->assertSame([], $result['entries']);
	}

	public function testDocumentTextMatchesAreSearchedFromThreeCharacters(): void {
		$scope = new CandidateScope('hr', true);
		$this->permissions->method('candidateScope')->willReturn($scope);
		$this->permissions->method('canViewCandidate')->willReturn(true);
		$this->documentMapper->expects($this->once())->method('findCandidateIdsByText')
			->with('kubernetes', 200)
			->willReturn([4, 9]);
		$this->candidateMapper->expects($this->once())->method('search')
			->with('kubernetes', $scope, $this->anything(), 0, [4, 9])
			->willReturn([$this->candidate(4)]);

		$result = $this->provider->search($this->user('hr'), $this->query('kubernetes', 5))->jsonSerialize();

		$this->assertSame('Jane 4', $result['entries'][0]->jsonSerialize()['title']);
	}

	public function testShortTermsDoNotSearchDocumentText(): void {
		$this->permissions->method('candidateScope')->willReturn(new CandidateScope('hr', true));
		$this->documentMapper->expects($this->never())->method('findCandidateIdsByText');
		$this->candidateMapper->expects($this->once())->method('search')
			->with('go', $this->anything(), $this->anything(), 0, [])
			->willReturn([]);

		$this->provider->search($this->user('hr'), $this->query('go', 5));
	}
}
