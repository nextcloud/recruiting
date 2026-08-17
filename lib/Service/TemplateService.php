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
	];

	public function __construct(
		private MailTemplateMapper $mapper,
		private IL10N $l10n,
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
	 * The effective template for a type: the custom default if one is
	 * marked, the built-in otherwise.
	 *
	 * @return array{subject: string, body: string}
	 */
	public function effectiveFor(string $type): array {
		foreach ($this->mapper->findByType($type) as $template) {
			if ($template->getIsDefault()) {
				return ['subject' => $template->getSubject(), 'body' => $template->getBody()];
			}
		}
		$builtin = $this->builtins()[$type] ?? null;
		if ($builtin === null) {
			throw new ValidationException($this->l10n->t('Unknown template type'));
		}
		return ['subject' => $builtin['subject'], 'body' => $builtin['body']];
	}

	/**
	 * @return array{subject: string, body: string}
	 * @throws ValidationException
	 */
	public function resolve(string $type, ?string $templateId): array {
		if ($templateId === null || $templateId === '' || str_starts_with($templateId, 'builtin:')) {
			if ($templateId !== null && str_starts_with($templateId, 'builtin:')) {
				$builtinType = substr($templateId, strlen('builtin:'));
				$builtin = $this->builtins()[$builtinType] ?? null;
				if ($builtin !== null) {
					return ['subject' => $builtin['subject'], 'body' => $builtin['body']];
				}
			}
			return $this->effectiveFor($type);
		}
		$template = $this->mapper->find((int)$templateId);
		return ['subject' => $template->getSubject(), 'body' => $template->getBody()];
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
		$this->mapper->update($template);
		if (array_key_exists('isDefault', $data)) {
			if ((bool)$data['isDefault']) {
				$this->markDefault($template);
			} else {
				$template->setIsDefault(false);
				$this->mapper->update($template);
			}
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
		$this->mapper->clearDefault($template->getType());
		$template->setIsDefault(true);
		$this->mapper->update($template);
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
				'body' => $this->l10n->t("Dear {{candidate_name}},\n\nThank you for applying for the {{opening_title}} position at {{company}}. We have received your application and will get back to you as soon as we have reviewed it.\n\nKind regards\n{{sender_name}}\n{{company}}"),
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
		];
	}
}
