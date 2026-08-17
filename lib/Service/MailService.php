<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\ValidationException;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * All candidate-facing mail: placeholder rendering, sending via the
 * instance SMTP and logging every mail on the candidate timeline (spec §4.5).
 */
class MailService {
	public function __construct(
		private IMailer $mailer,
		private ConfigService $config,
		private TemplateService $templates,
		private TimelineService $timeline,
		private ActivityPublisher $activity,
		private CandidateMapper $candidateMapper,
		private ISecureRandom $random,
		private IUserManager $userManager,
		private Defaults $defaults,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Replace {{placeholder}} tokens. Unknown tokens render empty — save-time
	 * validation already rejects them in stored templates.
	 *
	 * @param array<string, string> $context
	 */
	public function render(string $text, array $context): string {
		return (string)preg_replace_callback(
			'/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
			static fn (array $m) => $context[strtolower($m[1])] ?? '',
			$text,
		);
	}

	/**
	 * @param array<string, string> $extra additional placeholder values
	 * @return array<string, string>
	 */
	public function contextFor(Candidate $candidate, ?string $openingTitle, ?string $actorUid, array $extra = []): array {
		$senderName = $this->config->getSenderName();
		if ($senderName === '' && $actorUid !== null) {
			$senderName = $this->userManager->getDisplayName($actorUid) ?? $actorUid;
		}
		return array_merge([
			'candidate_name' => $candidate->getDisplayName(),
			'opening_title' => $openingTitle ?? '',
			'company' => $this->defaults->getName(),
			'sender_name' => $senderName,
			'interview_title' => '',
			'interview_link' => '',
		], $extra);
	}

	/**
	 * Rendered subject + body for the compose preview.
	 *
	 * @return array{subject: string, body: string, to: string}
	 * @throws ValidationException
	 */
	public function preview(Candidate $candidate, ?string $openingTitle, string $type, ?string $templateId, ?string $actorUid, array $extra = []): array {
		$template = $this->templates->resolve($type, $templateId);
		$context = $this->contextFor($candidate, $openingTitle, $actorUid, $extra);
		return [
			'subject' => $this->render($template['subject'], $context),
			'body' => $this->render($template['body'], $context),
			'to' => $candidate->getEmail(),
		];
	}

	/**
	 * Send a (previewed, possibly hand-edited) mail to the candidate and log
	 * it on the timeline.
	 *
	 * @throws ValidationException when the candidate has no address or sending fails
	 */
	public function sendToCandidate(Candidate $candidate, string $subject, string $body, ?string $actorUid): void {
		$to = $candidate->getEmail();
		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
			throw new ValidationException($this->l10n->t('This candidate has no valid email address'));
		}
		$subject = trim($subject);
		$body = trim($body);
		if ($subject === '' || $body === '') {
			throw new ValidationException($this->l10n->t('Subject and message must not be empty'));
		}

		$template = $this->mailer->createEMailTemplate('recruiting.candidateMail');
		$template->addHeader();
		foreach (preg_split('/\n{2,}/', $body) ?: [] as $paragraph) {
			$paragraph = trim($paragraph);
			if ($paragraph !== '') {
				$template->addBodyText(str_replace("\n", ' ', $paragraph), $paragraph);
			}
		}
		$template->addFooter();
		$template->setSubject($subject);

		$message = $this->mailer->createMessage();
		$message->setTo([$to => $candidate->getDisplayName()]);
		$message->useTemplate($template);

		$senderEmail = $this->config->getSenderEmail();
		if ($senderEmail !== '' && filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
			$senderName = $this->config->getSenderName();
			$message->setFrom($senderName !== '' ? [$senderEmail => $senderName] : [$senderEmail]);
		}
		$replyTo = $this->replyAddressFor($candidate);
		if ($replyTo !== null) {
			$message->setReplyTo([$replyTo]);
		}

		try {
			$failed = $this->mailer->send($message);
		} catch (\Exception $e) {
			$this->logger->error('Sending recruiting mail failed: ' . $e->getMessage(), ['exception' => $e]);
			throw new ValidationException($this->l10n->t('Sending the email failed. Please check the email server configuration.'));
		}
		if ($failed !== []) {
			throw new ValidationException($this->l10n->t('Sending the email failed. Please check the email server configuration.'));
		}

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_MAIL_SENT, $actorUid, [
			'to' => $to,
			'subject' => $subject,
			'body' => $body,
		]);
		$this->activity->publish($candidate, 'mail_sent', $actorUid, ['subject' => $subject]);
	}

	/**
	 * Per-candidate Reply-To (spec §4.2): "jobs+c{id}.{token}@…" routes the
	 * candidate's answer back onto their timeline instead of creating a new
	 * application in triage. Falls back to the plain mailbox when the
	 * configured IMAP user is not a plus-addressable email address.
	 */
	private function replyAddressFor(Candidate $candidate): ?string {
		$mailbox = $this->config->getImapConfig()['user'];
		if ($mailbox === '' || !filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
			return null;
		}
		[$local, $domain] = explode('@', $mailbox, 2);
		if (str_contains($local, '+')) {
			// already a plus address — nesting another tag would not survive
			return $mailbox;
		}

		$token = $candidate->getReplyToken();
		if ($token === null || $token === '') {
			$token = strtolower($this->random->generate(16, ISecureRandom::CHAR_ALPHANUMERIC));
			$candidate->setReplyToken($token);
			$this->candidateMapper->update($candidate);
		}
		return $local . '+c' . $candidate->getId() . '.' . $token . '@' . $domain;
	}
}
