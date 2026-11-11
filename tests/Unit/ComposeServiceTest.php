<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\MailTemplateMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\ComposeService;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\LanguageService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\PortalService;
use OCA\Recruiting\Service\TemplateService;
use OCA\Recruiting\Service\TimelineService;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * "Write email" from the sidebar: permission first, placeholders rendered,
 * sent through the regular candidate mail path and logged as a custom mail.
 */
class ComposeServiceTest extends TestCase {
	private PermissionService&MockObject $permissions;
	private IMailer&MockObject $mailer;
	private IEMailTemplate&MockObject $mailTemplate;
	private TimelineService&MockObject $timeline;
	private ConfigService&MockObject $config;
	private Candidate $candidate;
	private ComposeService $service;

	protected function setUp(): void {
		$this->candidate = new Candidate();
		$this->candidate->setId(7);
		$this->candidate->setOpeningId(3);
		$this->candidate->setDisplayName('Ada Lovelace');
		$this->candidate->setEmail('ada@example.org');
		$this->candidate->setLanguage('de');
		$this->candidate->setReplyToken('tok');
		$candidateMapper = $this->createMock(CandidateMapper::class);
		$candidateMapper->method('find')->with(7)->willReturn($this->candidate);
		$openingMapper = $this->createMock(OpeningMapper::class);
		$openingMapper->method('findTitle')->willReturn('Backend Engineer');

		$this->permissions = $this->createMock(PermissionService::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailTemplate = $this->createMock(IEMailTemplate::class);
		$this->mailer->method('createEMailTemplate')->willReturn($this->mailTemplate);
		$this->mailer->method('send')->willReturn([]);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->config->method('getImapConfig')->willReturn(['user' => 'jobs@example.com']);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []) => vsprintf($text, $p));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$factory->method('findGenericLanguage')->willReturn('en');
		$languages = new LanguageService($factory, $l10n);
		$templates = new TemplateService($this->createMock(MailTemplateMapper::class), $l10n, $languages);
		$defaults = $this->createMock(Defaults::class);
		$defaults->method('getName')->willReturn('ACME');
		$mail = new MailService(
			$this->mailer,
			$this->config,
			$templates,
			$this->timeline,
			$this->createMock(ActivityPublisher::class),
			$candidateMapper,
			$this->createMock(ISecureRandom::class),
			$this->createMock(IUserManager::class),
			$defaults,
			$l10n,
			new NullLogger(),
			$this->createMock(PortalService::class),
			$languages,
		);
		$this->service = new ComposeService($candidateMapper, $openingMapper, $this->permissions, $mail, $templates);
	}

	public function testManageAccessIsCheckedBeforeAnythingIsSent(): void {
		$this->permissions->method('assertCanManageCandidate')->willThrowException(new NotPermittedException('No'));
		$this->mailer->expects($this->never())->method('send');
		$this->timeline->expects($this->never())->method('record');

		$this->expectException(NotPermittedException::class);
		$this->service->writeEmail('intruder', 7, 'Hi', 'Hello');
	}

	public function testTheMailIsRenderedSentWithTheReplyTokenAndLoggedAsCustom(): void {
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setReplyTo')->with(['jobs+c7.tok@example.com']);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailTemplate->expects($this->once())->method('setSubject')->with('Your Backend Engineer application');
		$this->mailTemplate->expects($this->once())->method('addFooter')->with('', 'de');
		$this->timeline->expects($this->once())->method('record')->with(7, TimelineEvent::TYPE_MAIL_SENT, 'hr', [
			'to' => 'ada@example.org',
			'subject' => 'Your Backend Engineer application',
			'body' => 'Dear Ada Lovelace, one more document please. ACME',
			'kind' => 'custom',
		]);

		$this->service->writeEmail('hr', 7, 'Your {{opening_title}} application', 'Dear {{candidate_name}}, one more document please. {{company}}');
	}

	public function testUnknownPlaceholdersAreRejectedInsteadOfVanishing(): void {
		$this->mailer->expects($this->never())->method('send');
		$this->expectException(ValidationException::class);
		$this->service->writeEmail('hr', 7, 'Hi', 'Dear {{candidat_name}}');
	}

	public function testACandidateWithoutAddressCannotBeWrittenTo(): void {
		$this->candidate->setEmail('');
		$this->mailer->expects($this->never())->method('send');
		$this->expectException(ValidationException::class);
		$this->service->writeEmail('hr', 7, 'Hi', 'Hello');
	}
}
