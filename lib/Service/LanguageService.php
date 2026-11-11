<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Exception\ValidationException;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * Candidate languages. Codes are Nextcloud language codes ("de",
 * "de_DE", "pt_BR"), because candidate-facing built-in texts are rendered
 * through the app's translations; only languages the instance knows are
 * accepted.
 */
class LanguageService {
	public const MAX_LENGTH = 16;

	/** @var array<string, IL10N> */
	private array $l10nCache = [];

	public function __construct(
		private IFactory $l10nFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * Languages for a picker: the common ones first, then the rest by name.
	 *
	 * @return list<array{code: string, name: string}>
	 */
	public function languages(): array {
		$languages = $this->l10nFactory->getLanguages();
		$result = [];
		foreach (array_merge($languages['commonLanguages'] ?? [], $languages['otherLanguages'] ?? []) as $language) {
			$result[] = ['code' => (string)$language['code'], 'name' => (string)$language['name']];
		}
		return $result;
	}

	/**
	 * The known language code for user input, or null for empty or unknown
	 * input. Accepts BCP-47 spelling ("pt-BR") and any letter case.
	 */
	public function normalize(mixed $code): ?string {
		if (!is_string($code)) {
			return null;
		}
		$code = str_replace('-', '_', trim($code));
		if ($code === '' || strlen($code) > self::MAX_LENGTH) {
			return null;
		}
		foreach ($this->l10nFactory->findAvailableLanguages() as $available) {
			if (strcasecmp($available, $code) === 0) {
				return $available;
			}
		}
		return null;
	}

	/**
	 * Like {@see normalize()}, but unknown non-empty input is an error.
	 *
	 * @throws ValidationException
	 */
	public function parse(mixed $code): ?string {
		$language = $this->normalize($code);
		if ($language === null && is_string($code) && trim($code) !== '') {
			throw new ValidationException($this->l10n->t('Unknown language'));
		}
		return $language;
	}

	/**
	 * The language a visitor's browser asks for, among those the instance
	 * has (the public application form preselects it).
	 */
	public function requestLanguage(): string {
		return $this->l10nFactory->findLanguage();
	}

	/**
	 * The language candidate-facing text is written in when the candidate
	 * has none: the instance default, never the language of whoever sends.
	 */
	public function defaultLanguage(): string {
		return $this->l10nFactory->findGenericLanguage();
	}

	public function effectiveLanguage(?string $language): string {
		return $language !== null && $language !== '' ? $language : $this->defaultLanguage();
	}

	/**
	 * The app's translations in a language (null: the instance default).
	 */
	public function l10nFor(?string $language): IL10N {
		$language = $this->effectiveLanguage($language);
		return $this->l10nCache[$language] ??= $this->l10nFactory->get(Application::APP_ID, $language);
	}

	public function l10nForCandidate(Candidate $candidate): IL10N {
		return $this->l10nFor($candidate->getLanguage());
	}
}
