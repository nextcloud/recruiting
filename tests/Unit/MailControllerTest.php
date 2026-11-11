<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Controller\MailController;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\ComposeService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The compose endpoints: template choice and language in the preview,
 * interview placeholders only from the candidate's own interviews, and
 * "Write email" handed to the service.
 */
class MailControllerTest extends TestCase {
	private MailService&MockObject $mail;
	private PermissionService&MockObject $permissions;
	private TemplateService&MockObject $templates;
	private InterviewMapper&MockObject $interviewMapper;
	private InterviewService&MockObject $interviews;
	private ComposeService&MockObject $compose;
	private Candidate $candidate;
	private MailController $controller;

	protected function setUp(): void {
		$this->candidate = new Candidate();
		$this->candidate->setId(7);
		$this->candidate->setLanguage('de');
		$candidates = $this->createMock(CandidateService::class);
		$candidates->method('find')->willReturn($this->candidate);
		$this->mail = $this->createMock(MailService::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->templates = $this->createMock(TemplateService::class);
		$this->interviewMapper = $this->createMock(InterviewMapper::class);
		$this->interviews = $this->createMock(InterviewService::class);
		$this->compose = $this->createMock(ComposeService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new MailController(
			'recruiting',
			$this->createMock(IRequest::class),
			$this->mail,
			$candidates,
			$this->permissions,
			$this->createMock(OpeningMapper::class),
			$this->createMock(OfferMapper::class),
			$this->templates,
			$l10n,
			$session,
			$this->interviewMapper,
			$this->interviews,
			$this->compose,
		);
	}

	private function interview(int $candidateId): Interview {
		$interview = new Interview();
		$interview->setId(3);
		$interview->setCandidateId($candidateId);
		$interview->setTitle('Tech talk');
		return $interview;
	}

	public function testThePreviewOffersTheTemplatesOfTheTypeAndUsesTheCandidatesLanguage(): void {
		$this->mail->expects($this->once())->method('preview')
			->with($this->candidate, null, 'rejection', '4', 'hr', [])
			->willReturn(['subject' => 'S', 'body' => 'B', 'to' => '', 'templateId' => 4, 'language' => 'de']);
		$this->templates->expects($this->once())->method('resolve')->with('rejection', '4', 'de')
			->willReturn(['id' => 4, 'subject' => 'S', 'body' => 'B', 'language' => 'de']);
		$this->templates->method('listForType')->with('rejection')->willReturn([['id' => 'builtin:rejection'], ['id' => 4]]);

		$data = $this->controller->preview(7, 'rejection', '4')->getData();

		$this->assertSame(4, $data['templateId']);
		$this->assertSame([['id' => 'builtin:rejection'], ['id' => 4]], $data['templates']);
	}

	public function testThePreviewNeedsManageAccessFirst(): void {
		$this->permissions->method('assertCanManageCandidate')->willThrowException(new NotPermittedException('No'));
		$this->mail->expects($this->never())->method('preview');
		$this->templates->expects($this->never())->method('listForType');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->preview(7, 'rejection')->getStatus());
	}

	public function testInterviewPlaceholdersComeFromTheCandidatesOwnInterview(): void {
		$this->interviewMapper->method('find')->with(3)->willReturn($this->interview(7));
		$this->interviews->method('invitePlaceholders')->willReturn(['interview_title' => 'Tech talk', 'interview_link' => 'https://cloud/i/x']);
		$this->mail->expects($this->once())->method('preview')
			->with($this->candidate, null, 'interview_invite', 'builtin:interview_invite', 'hr', ['interview_title' => 'Tech talk', 'interview_link' => 'https://cloud/i/x'])
			->willReturn(['subject' => 'S', 'body' => 'B', 'to' => '', 'templateId' => 'builtin:interview_invite', 'language' => 'de']);
		$this->templates->method('resolve')->willReturn(['id' => 'builtin:interview_invite', 'subject' => 'S', 'body' => 'B', 'language' => 'de']);
		$this->templates->method('listForType')->willReturn([]);

		$response = $this->controller->preview(7, 'interview_invite', 'builtin:interview_invite', null, null, 3);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAnotherCandidatesInterviewIsRefused(): void {
		$this->interviewMapper->method('find')->willReturn($this->interview(99));
		$this->mail->expects($this->never())->method('preview');

		$response = $this->controller->preview(7, 'interview_invite', null, null, null, 3);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testWriteEmailIsHandedToTheService(): void {
		$this->compose->expects($this->once())->method('writeEmail')->with('hr', 7, 'Hi', 'Hello');
		$this->assertSame(['sent' => true], $this->controller->send(7, 'Hi', 'Hello')->getData());
	}
}
