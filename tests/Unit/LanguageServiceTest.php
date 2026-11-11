<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\LanguageService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LanguageServiceTest extends TestCase {
	private IFactory&MockObject $factory;
	private LanguageService $languages;

	protected function setUp(): void {
		$this->factory = $this->createMock(IFactory::class);
		$this->factory->method('findAvailableLanguages')->willReturn(['de', 'de_DE', 'en', 'pt_BR']);
		$this->factory->method('findGenericLanguage')->willReturn('de');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->languages = new LanguageService($this->factory, $l10n);
	}

	public static function inputs(): array {
		return [
			'plain' => ['de', 'de'],
			'bcp-47 spelling' => ['pt-BR', 'pt_BR'],
			'letter case' => ['PT_br', 'pt_BR'],
			'surrounding space' => [' de_DE ', 'de_DE'],
			'unknown' => ['tlh', null],
			'empty' => ['', null],
			'not a string' => [['de'], null],
			'null' => [null, null],
			'overlong' => [str_repeat('a', 40), null],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('inputs')]
	public function testNormalize(mixed $input, ?string $expected): void {
		$this->assertSame($expected, $this->languages->normalize($input));
	}

	public function testParseRejectsUnknownLanguagesButAcceptsEmptyOnes(): void {
		$this->assertNull($this->languages->parse(''));
		$this->assertNull($this->languages->parse(null));
		$this->assertSame('de', $this->languages->parse('de'));
		$this->expectException(ValidationException::class);
		$this->languages->parse('tlh');
	}

	public function testCandidatesWithoutALanguageGetTheInstanceDefault(): void {
		$german = $this->createMock(IL10N::class);
		$english = $this->createMock(IL10N::class);
		$this->factory->method('get')->willReturnMap([
			['recruiting', 'de', null, $german],
			['recruiting', 'en', null, $english],
		]);

		$candidate = new Candidate();
		$this->assertSame($german, $this->languages->l10nForCandidate($candidate));
		$candidate->setLanguage('en');
		$this->assertSame($english, $this->languages->l10nForCandidate($candidate));
	}

	public function testThePickerListsTheCommonLanguagesFirst(): void {
		$this->factory->method('getLanguages')->willReturn([
			'commonLanguages' => [['code' => 'en', 'name' => 'English (US)']],
			'otherLanguages' => [['code' => 'pt_BR', 'name' => 'Português Brasileiro']],
		]);
		$this->assertSame(['en', 'pt_BR'], array_column($this->languages->languages(), 'code'));
	}
}
