<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Service\ApplicationFormToken;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApplicationFormTokenTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private ISecureRandom&MockObject $random;
	private int $now = 1_800_000_000;
	private string $storedSecret = '';
	private ApplicationFormToken $token;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(fn () => $this->storedSecret);
		$this->random = $this->createMock(ISecureRandom::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$this->token = new ApplicationFormToken($this->appConfig, $this->random, $time);
	}

	private function issuedSecondsAgo(int $seconds, string $slug = 'backend-dev'): string {
		$this->now -= $seconds;
		$token = $this->token->issue($slug);
		$this->now += $seconds;
		return $token;
	}

	public function testTheSecretIsGeneratedOnceAndStoredSensitiveAndLazy(): void {
		$this->random->expects($this->once())->method('generate')->with(64)->willReturn(str_repeat('s', 64));
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('recruiting', 'public_form_secret', str_repeat('s', 64), true, true)
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->storedSecret = $value;
				return true;
			});

		$token = $this->token->issue('backend-dev');
		$this->token->issue('backend-dev');

		$this->assertMatchesRegularExpression('/^1800000000\.[0-9a-f]{64}$/', $token);
		$this->assertSame(hash_hmac('sha256', '1800000000|backend-dev', str_repeat('s', 64)), explode('.', $token)[1]);
	}

	public function testAFormFilledInAMinuteIsValid(): void {
		$this->storedSecret = 'secret';
		$this->assertSame(ApplicationFormToken::VALID, $this->token->check($this->issuedSecondsAgo(60), 'backend-dev'));
	}

	public function testBoundaries(): void {
		$this->storedSecret = 'secret';
		$this->assertSame(ApplicationFormToken::VALID, $this->token->check($this->issuedSecondsAgo(3), 'backend-dev'));
		$this->assertSame(ApplicationFormToken::VALID, $this->token->check($this->issuedSecondsAgo(24 * 3600), 'backend-dev'));
	}

	public function testTooFast(): void {
		$this->storedSecret = 'secret';
		$this->assertSame(ApplicationFormToken::TOO_FAST, $this->token->check($this->issuedSecondsAgo(2), 'backend-dev'));
		$this->assertSame(ApplicationFormToken::TOO_FAST, $this->token->check($this->issuedSecondsAgo(0), 'backend-dev'));
	}

	public function testATimestampFromTheFutureCountsAsTooFast(): void {
		$this->storedSecret = 'secret';
		$this->assertSame(ApplicationFormToken::TOO_FAST, $this->token->check($this->issuedSecondsAgo(-600), 'backend-dev'));
	}

	public function testExpiredAfterADay(): void {
		$this->storedSecret = 'secret';
		$this->assertSame(ApplicationFormToken::EXPIRED, $this->token->check($this->issuedSecondsAgo(24 * 3600 + 1), 'backend-dev'));
	}

	public function testATokenForAnotherOpeningIsInvalid(): void {
		$this->storedSecret = 'secret';
		$this->assertSame(ApplicationFormToken::INVALID, $this->token->check($this->issuedSecondsAgo(60, 'other-opening'), 'backend-dev'));
	}

	public function testATamperedTimestampIsInvalid(): void {
		$this->storedSecret = 'secret';
		[, $mac] = explode('.', $this->issuedSecondsAgo(1));
		$this->assertSame(ApplicationFormToken::INVALID, $this->token->check(($this->now - 60) . '.' . $mac, 'backend-dev'));
	}

	public function testATokenSignedWithAnotherSecretIsInvalid(): void {
		$this->storedSecret = 'secret';
		$token = $this->issuedSecondsAgo(60);
		$this->storedSecret = 'rotated';
		$this->assertSame(ApplicationFormToken::INVALID, $this->token->check($token, 'backend-dev'));
	}

	public function testMissingAndMalformedTokensAreInvalid(): void {
		$this->storedSecret = 'secret';
		foreach (['', '0', (string)($this->now - 60), ($this->now - 60) . '.', ($this->now - 60) . '.xyz', 'abc.' . str_repeat('a', 64)] as $value) {
			$this->assertSame(ApplicationFormToken::INVALID, $this->token->check($value, 'backend-dev'), $value);
		}
	}
}
