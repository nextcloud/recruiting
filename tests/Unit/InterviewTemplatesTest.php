<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\MailTemplateMapper;
use OCA\Recruiting\Service\TemplateService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The mails around rescheduling, cancelling and the nudge are ordinary
 * template types: built-in text, custom templates allowed.
 */
class InterviewTemplatesTest extends TestCase {
	private function service(): TemplateService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$mapper = $this->createMock(MailTemplateMapper::class);
		$mapper->method('findByType')->willReturn([]);
		$mapper->method('findAll')->willReturn([]);
		$mapper->method('insert')->willReturnArgument(0);
		return new TemplateService($mapper, $l10n);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function interviewTypes(): array {
		return [
			'reschedule' => [MailTemplate::TYPE_INTERVIEW_RESCHEDULE, '{{interview_link}}'],
			'nudge' => [MailTemplate::TYPE_INTERVIEW_NUDGE, '{{interview_link}}'],
			'cancelled' => [MailTemplate::TYPE_INTERVIEW_CANCELLED, '{{interview_title}}'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('interviewTypes')]
	public function testBuiltInTextExistsAndUsesOnlyKnownPlaceholders(string $type, string $expected): void {
		$service = $this->service();
		$this->assertContains($type, MailTemplate::TYPES);
		$template = $service->effectiveFor($type);
		$this->assertStringContainsString($expected, $template['body']);
		$service->assertValidPlaceholders($template['subject']);
		$service->assertValidPlaceholders($template['body']);
		$this->assertContains('builtin:' . $type, array_column($service->listAll(), 'id'));
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('interviewTypes')]
	public function testCustomTemplatesCanBeCreated(string $type): void {
		$template = $this->service()->create(['type' => $type, 'name' => 'Ours', 'subject' => 'About {{interview_title}}', 'body' => 'Hello {{candidate_name}}']);
		$this->assertSame($type, $template->getType());
	}
}
