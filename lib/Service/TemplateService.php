<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\MailTemplateMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCP\IL10N;

/**
 * Mail templates. Built-in defaults exist per type — kept out of the
 * database so they stay translatable — and recruiters can add their own
 * named templates and pick a default per type (spec §4.5).
 */
class TemplateService {
	/** Placeholders accepted in subject and body */
	public const PLACEHOLDERS = [
		'candidate_name',
		'opening_title',
		'company',
		'sender_name',
		'interview_title',
		'interview_link',
		'offer_job_title',
		'offer_start_date',
		'offer_valid_until',
		'portal_link',
		'interview_date',
		'interview_time',
		'interview_location',
	];

	public function __construct(
		private MailTemplateMapper $mapper,
		private IL10N $l10n,
		private LanguageService $languages,
	) {
	}

	/**
	 * All templates: built-ins first, then the custom ones.
	 */
	public function listAll(): array {
		$custom = array_map(static fn (MailTemplate $t) => $t->jsonSerialize(), $this->mapper->findAll());
		return array_merge(array_values($this->builtins()), $custom);
	}

	/**
	 * The templates a compose dialog offers for one mail type: the built-in
	 * first, then the custom ones.
	 *
	 * @return list<array{id: int|string, name: string, language: ?string, isDefault: bool, builtin: bool}>
	 */
	public function listForType(string $type): array {
		$result = [];
		$builtin = $this->builtins()[$type] ?? null;
		if ($builtin !== null) {
			$result[] = self::summary($builtin);
		}
		foreach ($this->mapper->findByType($type) as $template) {
			$result[] = self::summary($template->jsonSerialize());
		}
		return $result;
	}

	/**
	 * The effective template for a type and a candidate language: the
	 * custom default in that language, else one in its base language ("de"
	 * for "de_DE"), else the language-neutral custom default, else the
	 * built-in rendered in that language. A missing language means the
	 * instance default.
	 *
	 * @return array{id: int|string, subject: string, body: string, language: ?string}
	 * @throws ValidationException
	 */
	public function effectiveFor(string $type, ?string $language = null): array {
		$language = $this->languages->effectiveLanguage($language);
		$defaults = array_values(array_filter(
			$this->mapper->findByType($type),
			static fn (MailTemplate $template) => $template->getIsDefault(),
		));
		$primary = strtolower(explode('_', $language)[0]);
		$matches = [
			static fn (?string $l) => $l !== null && strcasecmp($l, $language) === 0,
			static fn (?string $l) => $l !== null && strtolower(explode('_', $l)[0]) === $primary,
			static fn (?string $l) => $l === null || $l === '',
		];
		foreach ($matches as $match) {
			foreach ($defaults as $template) {
				if ($match($template->getLanguage())) {
					return self::content($template->jsonSerialize());
				}
			}
		}
		$builtin = $this->builtinsIn($language)[$type] ?? null;
		if ($builtin === null) {
			throw new ValidationException($this->l10n->t('Unknown template type'));
		}
		return array_merge(self::content($builtin), ['language' => $language]);
	}

	/**
	 * The template a mail is written from: the chosen one, or the effective
	 * default when none was chosen. Built-ins are rendered in $language.
	 *
	 * @return array{id: int|string, subject: string, body: string, language: ?string}
	 * @throws ValidationException
	 */
	public function resolve(string $type, ?string $templateId, ?string $language = null): array {
		if ($templateId === null || $templateId === '') {
			return $this->effectiveFor($type, $language);
		}
		if (str_starts_with($templateId, 'builtin:')) {
			$language = $this->languages->effectiveLanguage($language);
			$builtin = $this->builtinsIn($language)[substr($templateId, strlen('builtin:'))] ?? null;
			if ($builtin === null) {
				return $this->effectiveFor($type, $language);
			}
			return array_merge(self::content($builtin), ['language' => $language]);
		}
		return self::content($this->mapper->find((int)$templateId)->jsonSerialize());
	}

	/**
	 * @throws ValidationException
	 */
	public function create(array $data): MailTemplate {
		[$type, $name, $subject, $body] = $this->validate($data);
		$template = new MailTemplate();
		$template->setType($type);
		$template->setName($name);
		$template->setSubject($subject);
		$template->setBody($body);
		$template->setLanguage($this->languages->parse($data['language'] ?? null));
		$template = $this->mapper->insert($template);
		if ((bool)($data['isDefault'] ?? false)) {
			$this->markDefault($template);
		}
		return $template;
	}

	/**
	 * @throws ValidationException
	 */
	public function update(int $id, array $data): MailTemplate {
		$template = $this->mapper->find($id);
		[, $name, $subject, $body] = $this->validate(array_merge(['type' => $template->getType()], $data));
		$template->setName($name);
		$template->setSubject($subject);
		$template->setBody($body);
		if (array_key_exists('language', $data)) {
			$template->setLanguage($this->languages->parse($data['language']));
		}
		$this->mapper->update($template);
		$isDefault = array_key_exists('isDefault', $data) ? (bool)$data['isDefault'] : $template->getIsDefault();
		if ($isDefault) {
			// also after a language change: one default per type and language
			$this->markDefault($template);
		} elseif ($template->getIsDefault()) {
			$template->setIsDefault(false);
			$this->mapper->update($template);
		}
		return $this->mapper->find($id);
	}

	public function delete(int $id): void {
		$this->mapper->delete($this->mapper->find($id));
	}

	/**
	 * Unknown placeholders are an error at save time (spec §3).
	 *
	 * @throws ValidationException
	 */
	public function assertValidPlaceholders(string $text): void {
		preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $matches);
		foreach ($matches[1] as $name) {
			if (!in_array(strtolower($name), self::PLACEHOLDERS, true)) {
				throw new ValidationException($this->l10n->t('Unknown placeholder: %s', ['{{' . $name . '}}']));
			}
		}
	}

	/**
	 * @return array{0: string, 1: string, 2: string, 3: string}
	 * @throws ValidationException
	 */
	private function validate(array $data): array {
		$type = (string)($data['type'] ?? '');
		if (!in_array($type, MailTemplate::TYPES, true)) {
			throw new ValidationException($this->l10n->t('Unknown template type'));
		}
		$name = trim((string)($data['name'] ?? ''));
		$subject = trim((string)($data['subject'] ?? ''));
		$body = (string)($data['body'] ?? '');
		if ($name === '' || $subject === '' || trim($body) === '') {
			throw new ValidationException($this->l10n->t('Name, subject and body are required'));
		}
		$this->assertValidPlaceholders($subject);
		$this->assertValidPlaceholders($body);
		return [$type, $name, $subject, $body];
	}

	private function markDefault(MailTemplate $template): void {
		$this->mapper->clearDefault($template->getType(), $template->getLanguage());
		$template->setIsDefault(true);
		$this->mapper->update($template);
	}

	/**
	 * @return array{id: int|string, subject: string, body: string, language: ?string}
	 */
	private static function content(array $template): array {
		return [
			'id' => $template['id'],
			'subject' => (string)$template['subject'],
			'body' => (string)$template['body'],
			'language' => $template['language'] ?? null,
		];
	}

	/**
	 * @return array{id: int|string, name: string, language: ?string, isDefault: bool, builtin: bool}
	 */
	private static function summary(array $template): array {
		return [
			'id' => $template['id'],
			'name' => (string)$template['name'],
			'language' => $template['language'] ?? null,
			'isDefault' => (bool)$template['isDefault'],
			'builtin' => (bool)$template['builtin'],
		];
	}

	/**
	 * The built-ins in a candidate's language. They are translatable
	 * strings, so they are rendered with that language's translations
	 * instead of the sender's.
	 *
	 * @return array<string, array>
	 */
	private function builtinsIn(string $language): array {
		$localized = clone $this;
		$localized->l10n = $this->languages->l10nFor($language);
		return $localized->builtins();
	}

	/**
	 * @return array<string, array>
	 */
	private function builtins(): array {
		return [
			MailTemplate::TYPE_RECEIPT => [
				'id' => 'builtin:' . MailTemplate::TYPE_RECEIPT,
				'type' => MailTemplate::TYPE_RECEIPT,
				'name' => $this->l10n->t('Confirmation of receipt (built-in)'),
				'subject' => $this->l10n->t('We received your application for {{opening_title}}'),
				'body' => $this->l10n->t("Dear {{candidate_name}},\n\nThank you for applying for the {{opening_title}} position at {{company}}. We have received your application and will get back to you as soon as we have reviewed it.\n\nYou can check the status of your application, add documents or withdraw it at any time here:\n{{portal_link}}\n\nKind regards\n{{sender_name}}\n{{company}}"),
				'isDefault' => false,
				'builtin' => true,
			],
			MailTemplate::TYPE_REJECTION => [
				'id' => 'builtin:' . MailTemplate::TYPE_REJECTION,
				'type' => MailTemplate::TYPE_REJECTION,
				'name' => $this->l10n->t('Rejection (built-in)'),
				'subject' => $this->l10n->t('Your application for {{opening_title}}'),
				'body' => $this->l10n->t("Dear {{candidate_name}},\n\nThank you for your interest in the {{opening_title}} position at {{company}} and for the time you invested in your application.\n\nAfter careful consideration we have decided not to move forward with your application. This was not an easy decision, and it is no reflection on your qualities as a professional.\n\nWe wish you all the best for your future career.\n\nKind regards\n{{sender_name}}\n{{company}}"),
				'isDefault' => false,
				'builtin' => true,
			],
			MailTemplate::TYPE_OFFER => [
				'id' => 'builtin:' . MailTemplate::TYPE_OFFER,
				'type' => MailTemplate::TYPE_OFFER,
				'name' => $this->l10n->t('Offer (built-in)'),
				'subject' => $this->l10n->t('Your offer for the {{offer_job_title}} position at {{company}}'),
				'body' => $this->l10n->t("Dear {{candidate_name}},\n\nWe are delighted to offer you the position of {{offer_job_title}} at {{company}}, starting {{offer_start_date}}.\n\nYou will receive the formal offer letter separately. This offer is valid until {{offer_valid_until}} — we would love to hear from you before then, and we are happy to answer any questions in the meantime.\n\nWe are excited about the prospect of working with you!\n\nKind regards\n{{sender_name}}\n{{company}}"),
				'isDefault' => false,
				'builtin' => true,
			],
			MailTemplate::TYPE_INTERVIEW_INVITE => [
				'id' => 'builtin:' . MailTemplate::TYPE_INTERVIEW_INVITE,
				'type' => MailTemplate::TYPE_INTERVIEW_INVITE,
				'name' => $this->l10n->t('Interview invitation (built-in)'),
				'subject' => $this->l10n->t('Interview invitation — {{opening_title}} at {{company}}'),
				'body' => $this->l10n->t("Dear {{candidate_name}},\n\nThank you for your application for the {{opening_title}} position. We would like to invite you to an interview ({{interview_title}}).\n\nPlease pick the time that suits you best:\n{{interview_link}}\n\nWe are looking forward to meeting you!\n\nKind regards\n{{sender_name}}\n{{company}}"),
				'isDefault' => false,
				'builtin' => true,
			],
			MailTemplate::TYPE_INTERVIEW_REMINDER => [
				'id' => 'builtin:' . MailTemplate::TYPE_INTERVIEW_REMINDER,
				'type' => MailTemplate::TYPE_INTERVIEW_REMINDER,
				'name' => $this->l10n->t('Interview reminder (built-in)'),
				'subject' => $this->l10n->t('Reminder: your interview on {{interview_date}}'),
				'body' => $this->l10n->t("Dear {{candidate_name}},\n\nThis is a friendly reminder of your interview ({{interview_title}}) for the {{opening_title}} position on {{interview_date}} at {{interview_time}}.\n\nWhere: {{interview_location}}\n\nAll details are on your interview page, where you can also let us know if you need to reschedule:\n{{interview_link}}\n\nYour application overview: {{portal_link}}\n\nWe are looking forward to meeting you!\n\nKind regards\n{{sender_name}}\n{{company}}"),
				'isDefault' => false,
				'builtin' => true,
			],
			MailTemplate::TYPE_CUSTOM => [
				'id' => 'builtin:' . MailTemplate::TYPE_CUSTOM,
				'type' => MailTemplate::TYPE_CUSTOM,
				'name' => $this->l10n->t('Personal email (built-in)'),
				'subject' => $this->l10n->t('Your application at {{company}}'),
				'body' => $this->l10n->t("Dear {{candidate_name}},\n\n\n\nKind regards\n{{sender_name}}\n{{company}}"),
				'isDefault' => false,
				'builtin' => true,
			],
		];
	}
}
