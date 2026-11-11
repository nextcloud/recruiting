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
use OCA\Recruiting\Reference\CandidateReferenceProvider;
use OCA\Recruiting\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Link previews for pasted candidate links: rich data only for people who
 * may view the candidate.
 */
class CandidateReferenceProviderTest extends TestCase {
	private CandidateMapper&MockObject $candidateMapper;
	private PermissionService&MockObject $permissions;
	private IUserSession&MockObject $session;
	private CandidateReferenceProvider $provider;

	protected function setUp(): void {
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->session = $this->createMock(IUserSession::class);
		$openingMapper = $this->createMock(OpeningMapper::class);
		$openingMapper->method('findTitle')->willReturnCallback(static fn (?int $id) => $id === 3 ? 'Backend Engineer' : null);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $path) => 'https://cloud.example' . $path);
		$urlGenerator->method('imagePath')->willReturn('/apps/recruiting/img/app-dark.svg');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []) => vsprintf($text, $p));
		$this->provider = new CandidateReferenceProvider($this->candidateMapper, $openingMapper, $this->permissions, $this->session, $urlGenerator, $l10n);
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}

	private function candidate(): Candidate {
		$candidate = new Candidate();
		$candidate->setId(42);
		$candidate->setOpeningId(3);
		$candidate->setDisplayName('Ada Lovelace');
		$candidate->setEmail('ada@example.org');
		$candidate->setStage(Candidate::STAGE_INTERVIEW);
		return $candidate;
	}

	public static function links(): array {
		return [
			'pretty url' => ['https://cloud.example/apps/recruiting/#/candidate/42', true],
			'with index.php' => ['https://cloud.example/index.php/apps/recruiting/#/candidate/42', true],
			'without slash before hash' => ['https://cloud.example/apps/recruiting#/candidate/42', true],
			'another instance' => ['https://evil.example/apps/recruiting/#/candidate/42', false],
			'an opening' => ['https://cloud.example/apps/recruiting/#/openings/3', false],
			'another app' => ['https://cloud.example/apps/deck/#/candidate/42', false],
			'trailing junk' => ['https://cloud.example/apps/recruiting/#/candidate/42x', false],
		];
	}

	#[DataProvider('links')]
	public function testMatchesCandidateLinksOfThisInstanceOnly(string $link, bool $matches): void {
		$this->assertSame($matches, $this->provider->matchReference($link));
	}

	public function testViewersGetNameOpeningAndStage(): void {
		$this->loginAs('manager');
		$this->candidateMapper->method('find')->with(42)->willReturn($this->candidate());
		$this->permissions->method('canViewCandidate')->with('manager')->willReturn(true);

		$reference = $this->provider->resolveReference('https://cloud.example/apps/recruiting/#/candidate/42');

		$this->assertNotNull($reference);
		$this->assertSame('Ada Lovelace', $reference->getTitle());
		$this->assertSame('Backend Engineer · Interview', $reference->getDescription());
		$this->assertStringNotContainsString('ada@example.org', (string)json_encode($reference->jsonSerialize()));
	}

	public function testOthersGetNoRichDataAtAll(): void {
		$this->loginAs('stranger');
		$this->candidateMapper->method('find')->willReturn($this->candidate());
		$this->permissions->method('canViewCandidate')->willReturn(false);

		$this->assertNull($this->provider->resolveReference('https://cloud.example/apps/recruiting/#/candidate/42'));
	}

	public function testGuestsAndUnknownCandidatesGetNothing(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->candidateMapper->expects($this->never())->method('find');
		$this->assertNull($this->provider->resolveReference('https://cloud.example/apps/recruiting/#/candidate/42'));
	}

	public function testAMissingCandidateGetsNothing(): void {
		$this->loginAs('manager');
		$this->candidateMapper->method('find')->willThrowException(new DoesNotExistException(''));
		$this->assertNull($this->provider->resolveReference('https://cloud.example/apps/recruiting/#/candidate/42'));
	}

	public function testThePreviewIsCachedPerUser(): void {
		$this->loginAs('manager');
		$this->assertSame('manager', $this->provider->getCacheKey('https://cloud.example/apps/recruiting/#/candidate/42'));
		$this->assertSame('candidate-42', $this->provider->getCachePrefix('https://cloud.example/apps/recruiting/#/candidate/42'));
	}
}
