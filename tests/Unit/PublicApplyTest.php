<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Controller\PublicController;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Service\ApplicationFormToken;
use OCA\Recruiting\Service\IngestionService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\PoolService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Bot defences and name rules of the public application form (spec §4.13).
 */
class PublicApplyTest extends TestCase {
	private IngestionService&MockObject $ingestion;
	private ApplicationFormToken&MockObject $formToken;
	/** @var array<string, string> */
	private array $params = [];
	private string $tokenState = ApplicationFormToken::VALID;
	private PublicController $controller;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => $this->params[$key] ?? $default,
		);
		$request->method('getUploadedFile')->willReturn(null);

		$opening = new Opening();
		$opening->setId(7);
		$opening->setTitle('Backend Engineer');
		$opening->setMailSlug('backend-dev');
		$opening->setStatus('open');
		$opening->setPublicApply(true);
		$openingMapper = $this->createMock(OpeningMapper::class);
		$openingMapper->method('findByMailSlug')->willReturn($opening);

		$this->ingestion = $this->createMock(IngestionService::class);
		$this->formToken = $this->createMock(ApplicationFormToken::class);
		$this->formToken->method('check')->willReturnCallback(fn () => $this->tokenState);
		$this->formToken->method('issue')->willReturn('fresh-token');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new PublicController(
			'recruiting',
			$request,
			$this->createMock(InterviewMapper::class),
			$this->createMock(InterviewService::class),
			$this->createMock(PoolService::class),
			$openingMapper,
			$this->ingestion,
			$this->formToken,
			$this->createMock(IInitialState::class),
			$this->createMock(Defaults::class),
			$l10n,
			new NullLogger(),
		);

		$this->params = [
			't' => 'some-token',
			'name' => 'Ada Lovelace',
			'email' => 'ada@example.org',
			'cover' => 'Hello',
			'consent' => 'on',
		];
	}

	private function submit(): TemplateResponse {
		return $this->controller->submitApplication('backend-dev');
	}

	public function testTheFormCarriesASignedToken(): void {
		$response = $this->controller->showApplication('backend-dev');
		$this->assertSame('fresh-token', $response->getParams()['token']);
	}

	public function testAValidSubmissionIsIngested(): void {
		$this->ingestion->expects($this->once())->method('ingestWebApplication')
			->with($this->isInstanceOf(Opening::class), 'Ada Lovelace', 'ada@example.org', 'Hello', null);

		$response = $this->submit();

		$this->assertTrue($response->getParams()['done']);
	}

	public function testTheTokenIsCheckedAgainstTheOpeningSlug(): void {
		$this->formToken->expects($this->once())->method('check')->with('some-token', 'backend-dev');
		$this->submit();
	}

	public static function silentlyDroppedStates(): array {
		return [
			'missing or forged signature' => [ApplicationFormToken::INVALID],
			'faster than a person' => [ApplicationFormToken::TOO_FAST],
		];
	}

	#[DataProvider('silentlyDroppedStates')]
	public function testBotsGetAThankYouPageButNothingIsCreated(string $state): void {
		$this->tokenState = $state;
		$this->ingestion->expects($this->never())->method('ingestWebApplication');

		$response = $this->submit();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getParams()['done']);
	}

	public function testAnExpiredFormIsShownAgainWithAFreshTokenAndTheInputs(): void {
		$this->tokenState = ApplicationFormToken::EXPIRED;
		$this->ingestion->expects($this->never())->method('ingestWebApplication');

		$response = $this->submit();

		$params = $response->getParams();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($params['done']);
		$this->assertSame(['The form has expired. Please submit it again.'], $params['errors']);
		$this->assertSame('fresh-token', $params['token']);
		$this->assertSame(['name' => 'Ada Lovelace', 'email' => 'ada@example.org', 'cover' => 'Hello'], $params['values']);
	}

	public function testTheHoneypotWinsOverEverything(): void {
		$this->params['website'] = 'http://spam.test';
		$this->formToken->expects($this->never())->method('check');
		$this->ingestion->expects($this->never())->method('ingestWebApplication');

		$this->assertTrue($this->submit()->getParams()['done']);
	}

	public static function rejectedNames(): array {
		return [
			'too long' => [str_repeat('a', 121), 'Your name is too long.'],
			'scheme url' => ['Prize at http://evil.test', 'Please enter your name without links or email addresses.'],
			'www' => ['Visit www.evil.test', 'Please enter your name without links or email addresses.'],
			'host with path' => ['Prize evil.test/win', 'Please enter your name without links or email addresses.'],
			'at sign' => ['ada@example.org', 'Please enter your name without links or email addresses.'],
		];
	}

	#[DataProvider('rejectedNames')]
	public function testLinkLikeOrLongNamesAreRejectedWithTheInputsKept(string $name, string $error): void {
		$this->params['name'] = $name;
		$this->ingestion->expects($this->never())->method('ingestWebApplication');

		$response = $this->submit();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertContains($error, $response->getParams()['errors']);
		$this->assertSame($name, $response->getParams()['values']['name']);
		$this->assertSame('fresh-token', $response->getParams()['token']);
	}

	public static function acceptedNames(): array {
		return [
			'exactly 120 characters' => [str_repeat('a', 120)],
			'initials' => ['J. R. R. Tolkien'],
			'dotted without path' => ['J.Smith'],
			'unicode' => ['Zoë Ångström'],
		];
	}

	#[DataProvider('acceptedNames')]
	public function testOrdinaryNamesPass(string $name): void {
		$this->params['name'] = $name;
		$this->ingestion->expects($this->once())->method('ingestWebApplication');

		$this->assertTrue($this->submit()->getParams()['done']);
	}
}
