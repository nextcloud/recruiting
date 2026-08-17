<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\MailTemplateMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\TemplateService;
use OCA\Recruiting\Service\TimelineService;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MailRenderingTest extends TestCase {
	private MailService $mail;
	private TemplateService $templates;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $params = []) => vsprintf(str_replace('%s', '%s', $text), is_array($params) ? $params : [$params]),
		);
		$this->templates = new TemplateService($this->createMock(MailTemplateMapper::class), $l10n);
		$this->mail = new MailService(
			$this->createMock(IMailer::class),
			$this->createMock(ConfigService::class),
			$this->templates,
			$this->createMock(TimelineService::class),
			$this->createMock(ActivityPublisher::class),
			$this->createMock(\OCA\Recruiting\Db\CandidateMapper::class),
			$this->createMock(\OCP\Security\ISecureRandom::class),
			$this->createMock(IUserManager::class),
			$this->createMock(Defaults::class),
			$l10n,
			new NullLogger(),
		);
	}

	public function testRenderReplacesPlaceholders(): void {
		$result = $this->mail->render(
			'Dear {{candidate_name}}, thanks for applying at {{ company }}!',
			['candidate_name' => 'Ada Lovelace', 'company' => 'ACME'],
		);
		$this->assertSame('Dear Ada Lovelace, thanks for applying at ACME!', $result);
	}

	public function testRenderIsCaseInsensitiveAndUnknownRendersEmpty(): void {
		$result = $this->mail->render('A {{CANDIDATE_NAME}} B {{unknown_thing}} C', ['candidate_name' => 'X']);
		$this->assertSame('A X B  C', $result);
	}

	public function testValidPlaceholdersPass(): void {
		$this->templates->assertValidPlaceholders('Hi {{candidate_name}}, {{opening_title}} at {{company}} — {{interview_link}}');
		$this->addToAssertionCount(1);
	}

	public function testUnknownPlaceholderIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->templates->assertValidPlaceholders('Hello {{candidate_nmae}}');
	}

	public function testBuiltinTemplatesResolveForEveryType(): void {
		foreach (['rejection', 'interview_invite', 'receipt_confirmation'] as $type) {
			$template = $this->templates->effectiveFor($type);
			$this->assertNotSame('', $template['subject']);
			$this->assertStringContainsString('{{candidate_name}}', $template['body']);
		}
	}

	public function testInviteBuiltinContainsTheSchedulingLink(): void {
		$template = $this->templates->effectiveFor('interview_invite');
		$this->assertStringContainsString('{{interview_link}}', $template['body']);
	}
}
