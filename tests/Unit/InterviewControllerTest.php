<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Controller\InterviewController;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Service\InterviewFeedbackService;
use OCA\Recruiting\Service\InterviewService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InterviewControllerTest extends TestCase {
	private InterviewService&MockObject $interviews;
	private InterviewFeedbackService&MockObject $feedback;
	private IRequest&MockObject $request;

	private function controller(): InterviewController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('boss');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new InterviewController('recruiting', $this->request, $this->interviews, $session, $this->feedback);
	}

	protected function setUp(): void {
		$this->interviews = $this->createMock(InterviewService::class);
		$this->feedback = $this->createMock(InterviewFeedbackService::class);
		$this->request = $this->createMock(IRequest::class);
	}

	public function testCancelPassesTheMailOn(): void {
		$this->interviews->expects($this->once())->method('cancel')->with('boss', 8, 'Sorry', 'Body')->willReturn(['id' => 8]);
		$this->assertSame(['id' => 8], $this->controller()->cancel(8, 'Sorry', 'Body')->getData());
	}

	public function testRescheduleTakesTheRequestParameters(): void {
		$this->request->method('getParams')->willReturn(['slots' => [['start' => '2026-08-20T13:00:00Z']]]);
		$this->interviews->expects($this->once())->method('reschedule')
			->with('boss', 8, ['slots' => [['start' => '2026-08-20T13:00:00Z']]])->willReturn(['interview' => []]);
		$this->controller()->reschedule(8);
	}

	public function testCancelPreviewAndFeedbackOwed(): void {
		$this->interviews->expects($this->once())->method('cancelPreview')->with('boss', 8)->willReturn(['subject' => 'S']);
		$this->feedback->expects($this->once())->method('owed')->with('boss')->willReturn([]);
		$controller = $this->controller();
		$this->assertSame(['subject' => 'S'], $controller->cancelPreview(8)->getData());
		$this->assertSame([], $controller->feedbackOwed()->getData());
	}

	public function testFeedbackErrorsBecomeForbidden(): void {
		$this->feedback->method('submit')->with('boss', 8, 'yes', 'Good')->willThrowException(new NotPermittedException('no'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->feedback(8, 'yes', 'Good')->getStatus());
	}
}
