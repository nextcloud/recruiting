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
use OCA\Recruiting\Service\LanguageService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\PortalService;
use OCA\Recruiting\Service\TemplateService;
use OCA\Recruiting\Service\TimelineService;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MailRenderingTest extends TestCase {
	private MailService $mail;
	private TemplateService $templates;
	private LanguageService $languages;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $params = []) => vsprintf(str_replace('%s', '%s', $text), is_array($params) ? $params : [$params]),
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$factory->method('findGenericLanguage')->willReturn('en');
		$this->languages = new LanguageService($factory, $l10n);
		$this->templates = new TemplateService($this->createMock(MailTemplateMapper::class), $l10n, $this->languages);
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
			$this->createMock(PortalService::class),
			$this->languages,
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
		foreach (['rejection', 'interview_invite', 'receipt_confirmation', 'offer', 'interview_reminder', 'custom'] as $type) {
			$template = $this->templates->effectiveFor($type);
			$this->assertNotSame('', $template['subject']);
			$this->assertStringContainsString('{{candidate_name}}', $template['body']);
		}
	}

	public function testReceiptAndReminderCarryThePortalLink(): void {
		$this->assertStringContainsString('{{portal_link}}', $this->templates->effectiveFor('receipt_confirmation')['body']);
		$reminder = $this->templates->effectiveFor('interview_reminder');
		foreach (['{{portal_link}}', '{{interview_date}}', '{{interview_time}}', '{{interview_location}}', '{{interview_link}}'] as $placeholder) {
			$this->assertStringContainsString($placeholder, $reminder['body']);
		}
		// Every placeholder the built-ins use passes the save-time validation
		$this->templates->assertValidPlaceholders($reminder['subject'] . $reminder['body']);
	}

	public function testContextCarriesThePortalLink(): void {
		$portal = $this->createMock(PortalService::class);
		$portal->method('portalUrl')->willReturn('https://cloud.example/apps/recruiting/portal/abc');
		$l10n = $this->createMock(IL10N::class);
		$mail = new MailService(
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
			$portal,
			$this->languages,
		);
		$candidate = new \OCA\Recruiting\Db\Candidate();
		$candidate->setId(3);
		$candidate->setDisplayName('Ada');
		$rendered = $mail->render('See {{portal_link}}', $mail->contextFor($candidate, 'Dev', null));
		$this->assertSame('See https://cloud.example/apps/recruiting/portal/abc', $rendered);
	}

	public function testInviteBuiltinContainsTheSchedulingLink(): void {
		$template = $this->templates->effectiveFor('interview_invite');
		$this->assertStringContainsString('{{interview_link}}', $template['body']);
	}

	public static function mailNames(): array {
		return [
			'plain name' => ['Ada Lovelace', 'Ada Lovelace'],
			'unicode name' => ['Zoë Ångström-Øberg', 'Zoë Ångström-Øberg'],
			'initials with spaces' => ['J. R. R. Tolkien', 'J. R. R. Tolkien'],
			'line breaks and tabs' => ["Ada\r\nBcc: x@evil.test\tLovelace", 'Ada Bcc: Lovelace'],
			'control and bidi characters' => ["Ada\u{0000}\u{202E}Lovelace", 'Ada Lovelace'],
			'scheme url' => ['Claim your prize at http://evil.example/win now', 'Claim your prize at now'],
			'www' => ['Visit www.evil.test today', 'Visit today'],
			'bare domain' => ['Prize evil.com', 'Prize'],
			'domain with path' => ['See evil.co/x', 'See'],
			'email address' => ['Mail me at win@evil.test', 'Mail me at'],
			'only a url' => ['https://evil.example', 'applicant'],
			'empty' => ['', 'applicant'],
			'whitespace only' => ["  \n ", 'applicant'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('mailNames')]
	public function testNameForMail(string $name, string $expected): void {
		$this->assertSame($expected, $this->mail->nameForMail($name));
	}

	public function testNameForMailIsCapped(): void {
		$result = $this->mail->nameForMail(str_repeat('Ab ', 60));
		$this->assertLessThanOrEqual(MailService::MAIL_NAME_MAX_LENGTH, mb_strlen($result));
		$this->assertSame(trim($result), $result);
	}

	public function testPlaceholderContextUsesTheSanitizedNameButKeepsTheStoredOne(): void {
		$candidate = new \OCA\Recruiting\Db\Candidate();
		$candidate->setDisplayName("Winner\nclaim at https://evil.example/prize");

		$context = $this->mail->contextFor($candidate, 'Backend Engineer', null);

		$this->assertSame('Winner claim at', $context['candidate_name']);
		$this->assertSame("Winner\nclaim at https://evil.example/prize", $candidate->getDisplayName());
	}

	public function testReceiptRendersTheSanitizedName(): void {
		$candidate = new \OCA\Recruiting\Db\Candidate();
		$candidate->setDisplayName('www.spam.test');

		$mail = $this->mail->preview($candidate, 'Backend Engineer', 'receipt_confirmation', null, null);

		$this->assertStringNotContainsString('spam.test', $mail['body']);
		$this->assertStringContainsString('applicant', $mail['body']);
	}

	public function testThePreviewNamesTheTemplateItWasWrittenFrom(): void {
		$candidate = new \OCA\Recruiting\Db\Candidate();
		$candidate->setDisplayName('Ada');

		$mail = $this->mail->preview($candidate, 'Backend Engineer', 'receipt_confirmation', null, null);

		$this->assertSame('builtin:receipt_confirmation', $mail['templateId']);
		$this->assertSame('en', $mail['language']);
	}
}
