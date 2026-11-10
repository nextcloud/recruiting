<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Controller\PortalController;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\PortalService;
use OCP\AppFramework\Http;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PortalControllerTest extends TestCase {
	private const TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV';

	private PortalService&MockObject $portal;
	private IRequest&MockObject $request;
	private PortalController $controller;
	/** @var string[] */
	private array $tempFiles = [];

	protected function setUp(): void {
		$this->portal = $this->createMock(PortalService::class);
		$this->portal->method('view')->willReturn(['status' => 'received']);
		$this->request = $this->createMock(IRequest::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$this->controller = new PortalController('recruiting', $this->request, $this->portal, $this->createMock(Defaults::class), $l10n, new NullLogger());
	}

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			@unlink($file);
		}
	}

	private function knownCandidate(): Candidate {
		$candidate = new Candidate();
		$candidate->setId(5);
		$this->portal->method('findByToken')->with(self::TOKEN)->willReturn($candidate);
		return $candidate;
	}

	public function testUnknownTokensGetANeutralThrottled404OnEveryAction(): void {
		$this->portal->method('findByToken')->willReturn(null);
		$this->portal->expects($this->never())->method('withdraw');
		$this->portal->expects($this->never())->method('requestDeletion');
		$this->portal->expects($this->never())->method('addDocument');
		foreach (['show', 'withdraw', 'requestDeletion', 'uploadDocument'] as $action) {
			$response = $this->controller->$action(self::TOKEN);
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $action);
			$this->assertTrue($response->isThrottled(), $action);
			$this->assertTrue($response->getParams()['notFound'], $action);
			$this->assertArrayNotHasKey('view', $response->getParams());
		}
	}

	public function testShowRendersTheView(): void {
		$this->knownCandidate();
		$response = $this->controller->show(self::TOKEN);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('public-portal', $response->getTemplateName());
		$this->assertSame(['status' => 'received'], $response->getParams()['view']);
	}

	public function testWithdrawNeedsTheConfirmation(): void {
		$this->knownCandidate();
		$this->request->method('getParam')->with('confirm')->willReturn('');
		$this->portal->expects($this->never())->method('withdraw');
		$response = $this->controller->withdraw(self::TOKEN);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertNotEmpty($response->getParams()['errors']);
	}

	public function testConfirmedWithdrawal(): void {
		$candidate = $this->knownCandidate();
		$this->request->method('getParam')->with('confirm')->willReturn('on');
		$this->portal->expects($this->once())->method('withdraw')->with($candidate);
		$response = $this->controller->withdraw(self::TOKEN);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNotNull($response->getParams()['notice']);
	}

	public function testWithdrawingAClosedApplicationShowsTheReason(): void {
		$this->knownCandidate();
		$this->request->method('getParam')->willReturn('on');
		$this->portal->method('withdraw')->willThrowException(new ValidationException('This application is already closed.'));
		$response = $this->controller->withdraw(self::TOKEN);
		$this->assertSame(['This application is already closed.'], $response->getParams()['errors']);
	}

	public function testDeletionRequest(): void {
		$candidate = $this->knownCandidate();
		$this->request->method('getParam')->with('confirm')->willReturn('1');
		$this->portal->expects($this->once())->method('requestDeletion')->with($candidate);
		$this->assertSame(Http::STATUS_OK, $this->controller->requestDeletion(self::TOKEN)->getStatus());
	}

	public function testUploadWithoutAFile(): void {
		$this->knownCandidate();
		$this->request->method('getUploadedFile')->willReturn(null);
		$this->portal->expects($this->never())->method('addDocument');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->uploadDocument(self::TOKEN)->getStatus());
	}

	public function testUploadPassesTheFileOn(): void {
		$candidate = $this->knownCandidate();
		$path = (string)tempnam(sys_get_temp_dir(), 'rcr-up');
		$this->tempFiles[] = $path;
		file_put_contents($path, '%PDF-1.4');
		$this->request->method('getUploadedFile')->with('document')->willReturn([
			'name' => 'cv.pdf', 'type' => 'application/pdf', 'tmp_name' => $path, 'error' => \UPLOAD_ERR_OK,
		]);
		$this->portal->expects($this->once())->method('addDocument')->with($candidate, 'cv.pdf', 'application/pdf', '%PDF-1.4');
		$this->assertSame(Http::STATUS_OK, $this->controller->uploadDocument(self::TOKEN)->getStatus());
	}
}
