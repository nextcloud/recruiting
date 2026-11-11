<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\MailTemplateMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\LanguageService;
use OCA\Recruiting\Service\TemplateService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Which template a mail is written from, and in which language the
 * built-ins are rendered.
 */
class TemplateLanguageTest extends TestCase {
	private MailTemplateMapper&MockObject $mapper;
	/** @var MailTemplate[] */
	private array $custom = [];
	private TemplateService $templates;

	protected function setUp(): void {
		$this->mapper = $this->createMock(MailTemplateMapper::class);
		$this->mapper->method('findByType')->willReturnCallback(
			fn (string $type) => array_values(array_filter($this->custom, static fn (MailTemplate $t) => $t->getType() === $type)),
		);
		$this->mapper->method('find')->willReturnCallback(function (int $id): MailTemplate {
			foreach ($this->custom as $template) {
				if ($template->getId() === $id) {
					return $template;
				}
			}
			throw new \OCP\AppFramework\Db\DoesNotExistException('');
		});

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(fn (string $app, ?string $lang) => $this->l10n((string)$lang));
		$factory->method('findGenericLanguage')->willReturn('fr');
		$factory->method('findAvailableLanguages')->willReturn(['de', 'de_DE', 'en', 'fr', 'pt_BR']);
		$sender = $this->l10n('sender');
		$this->templates = new TemplateService($this->mapper, $sender, new LanguageService($factory, $sender));
	}

	/**
	 * Translations that mark every string with their language.
	 */
	private function l10n(string $language): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => '[' . $language . '] ' . $text);
		return $l10n;
	}

	private function custom(int $id, string $type, ?string $language, bool $isDefault, string $subject): MailTemplate {
		$template = new MailTemplate();
		$template->setId($id);
		$template->setType($type);
		$template->setName('T' . $id);
		$template->setSubject($subject);
		$template->setBody('Body ' . $id);
		$template->setLanguage($language);
		$template->setIsDefault($isDefault);
		$this->custom[] = $template;
		return $template;
	}

	public function testBuiltinsAreRenderedInTheCandidatesLanguage(): void {
		$template = $this->templates->effectiveFor('rejection', 'de');
		$this->assertStringStartsWith('[de] ', $template['subject']);
		$this->assertStringStartsWith('[de] ', $template['body']);
		$this->assertSame('builtin:rejection', $template['id']);
		$this->assertSame('de', $template['language']);
	}

	public function testWithoutACandidateLanguageBuiltinsUseTheInstanceDefaultNotTheSender(): void {
		$template = $this->templates->effectiveFor('receipt_confirmation', null);
		$this->assertStringStartsWith('[fr] ', $template['subject']);
		$this->assertSame('fr', $template['language']);
	}

	public function testAnExplicitlyChosenBuiltinIsAlsoRenderedInTheCandidatesLanguage(): void {
		$template = $this->templates->resolve('rejection', 'builtin:rejection', 'pt_BR');
		$this->assertStringStartsWith('[pt_BR] ', $template['subject']);
	}

	public function testTheDefaultInTheCandidatesLanguageWins(): void {
		$this->custom(1, 'rejection', null, true, 'Neutral');
		$this->custom(2, 'rejection', 'de', true, 'Deutsch');
		$this->custom(3, 'rejection', 'en', false, 'English, not default');

		$this->assertSame('Deutsch', $this->templates->effectiveFor('rejection', 'de')['subject']);
		$this->assertSame(2, $this->templates->effectiveFor('rejection', 'de')['id']);
	}

	public function testARegionalLanguageFallsBackToItsBaseLanguage(): void {
		$this->custom(1, 'rejection', null, true, 'Neutral');
		$this->custom(2, 'rejection', 'de', true, 'Deutsch');

		$this->assertSame('Deutsch', $this->templates->effectiveFor('rejection', 'de_DE')['subject']);
	}

	public function testOtherwiseTheLanguageNeutralDefaultIsUsed(): void {
		$this->custom(1, 'rejection', null, true, 'Neutral');
		$this->custom(2, 'rejection', 'de', true, 'Deutsch');

		$this->assertSame('Neutral', $this->templates->effectiveFor('rejection', 'en')['subject']);
	}

	public function testADefaultInAnotherLanguageIsNeverSentInsteadOfTheBuiltin(): void {
		$this->custom(2, 'rejection', 'de', true, 'Deutsch');

		$template = $this->templates->effectiveFor('rejection', 'en');
		$this->assertSame('builtin:rejection', $template['id']);
		$this->assertStringStartsWith('[en] ', $template['subject']);
	}

	public function testWithoutACandidateLanguageTheDefaultForTheInstanceLanguageIsUsed(): void {
		$this->custom(1, 'rejection', null, true, 'Neutral');
		$this->custom(2, 'rejection', 'fr', true, 'Français');

		$this->assertSame('Français', $this->templates->effectiveFor('rejection', null)['subject']);
	}

	public function testAChosenCustomTemplateIsUsedAsItIs(): void {
		$this->custom(1, 'rejection', null, true, 'Neutral');
		$this->custom(5, 'rejection', 'en', false, 'English');

		$template = $this->templates->resolve('rejection', '5', 'de');
		$this->assertSame('English', $template['subject']);
		$this->assertSame('en', $template['language']);
	}

	public function testNewTemplateTypesWorkWithoutLanguageSpecificCode(): void {
		foreach (MailTemplate::TYPES as $type) {
			$this->assertStringStartsWith('[de] ', $this->templates->effectiveFor($type, 'de')['subject'], $type);
		}
	}

	public function testThePickerListsTheBuiltinAndTheCustomTemplatesOfOneType(): void {
		$this->custom(1, 'rejection', 'de', true, 'Deutsch');
		$this->custom(2, 'offer', null, true, 'Offer');

		$list = $this->templates->listForType('rejection');

		$this->assertSame(['builtin:rejection', 1], array_column($list, 'id'));
		$this->assertSame([null, 'de'], array_column($list, 'language'));
		$this->assertSame([true, false], array_column($list, 'builtin'));
		$this->assertArrayNotHasKey('body', $list[0]);
	}

	public function testTemplatesStoreAValidatedLanguage(): void {
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(static function (MailTemplate $t) {
			$t->setId(9);
			return $t;
		});
		$template = $this->templates->create(['type' => 'rejection', 'name' => 'DE', 'subject' => 'S', 'body' => 'B', 'language' => 'pt-br']);
		$this->assertSame('pt_BR', $template->getLanguage());
	}

	public function testAnUnknownTemplateLanguageIsRejected(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(ValidationException::class);
		$this->templates->create(['type' => 'rejection', 'name' => 'X', 'subject' => 'S', 'body' => 'B', 'language' => 'klingon']);
	}

	public function testThereIsOneDefaultPerTypeAndLanguage(): void {
		$this->mapper->method('insert')->willReturnCallback(static function (MailTemplate $t) {
			$t->setId(9);
			return $t;
		});
		$this->mapper->expects($this->once())->method('clearDefault')->with('rejection', 'de');
		$this->templates->create(['type' => 'rejection', 'name' => 'DE', 'subject' => 'S', 'body' => 'B', 'language' => 'de', 'isDefault' => true]);
	}

	public function testMovingADefaultToAnotherLanguageClearsThatLanguagesDefault(): void {
		$this->custom(4, 'rejection', null, true, 'Neutral');
		$this->mapper->expects($this->once())->method('clearDefault')->with('rejection', 'en');
		$this->templates->update(4, ['name' => 'T4', 'subject' => 'S', 'body' => 'B', 'language' => 'en']);
	}
}
