<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\AppInfo\Application;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use Psr\Log\LoggerInterface;

/**
 * AI assistance via the TaskProcessing API (spec §4.7). Everything here is
 * optional and degrades silently when no provider (e.g. Nextcloud
 * Assistant) is configured. AI never acts on its own: extraction only fills
 * *empty* fields, summaries are labeled, drafts and hints are suggestions a
 * human reviews.
 */
class AiService {
	public const ACTION_SUMMARY = 'summary';
	public const ACTION_HINT = 'hint';
	public const ACTION_DRAFT_REJECTION = 'draft_rejection';
	public const ACTION_DRAFT_INVITE = 'draft_invite';
	public const ACTION_DRAFT_OFFER = 'draft_offer';
	public const ACTION_QUESTIONS = 'questions';

	public const MANAGE_ACTIONS = [self::ACTION_SUMMARY, self::ACTION_DRAFT_REJECTION, self::ACTION_DRAFT_INVITE, self::ACTION_DRAFT_OFFER, self::ACTION_QUESTIONS];
	public const VOTE_ACTIONS = [self::ACTION_HINT];

	private const MATERIAL_LIMIT = 6000;

	public function __construct(
		private ITaskProcessingManager $taskProcessing,
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private TimelineEventMapper $eventMapper,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		try {
			return in_array(TextToText::ID, $this->taskProcessing->getAvailableTaskTypeIds(), true);
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Fired after mail ingestion: extract contact details and produce the
	 * card summary. The result is applied asynchronously by the listener.
	 */
	public function scheduleIntakeAnalysis(Candidate $candidate): void {
		if (!$this->isAvailable()) {
			return;
		}
		$prompt = "You are helping an HR tool process an incoming job application.\n"
			. "Analyze the application below and answer with ONLY a JSON object, no other text:\n"
			. '{"name": "<applicant full name or empty string>", "email": "<email or empty>", "phone": "<phone or empty>", "summary": "<max 3 sentences: who they are, their key skills and experience>"}'
			. "\n\nApplication:\n" . $this->material($candidate);
		try {
			$this->taskProcessing->scheduleTask(new Task(
				TextToText::ID,
				['input' => $prompt],
				Application::APP_ID,
				null,
				'intake:' . $candidate->getId(),
			));
		} catch (\Exception $e) {
			$this->logger->info('Could not schedule AI intake analysis: ' . $e->getMessage());
		}
	}

	/**
	 * On-demand request from the UI; the client polls taskResult().
	 *
	 * @throws ValidationException
	 */
	public function request(string $uid, Candidate $candidate, string $action): int {
		if (!$this->isAvailable()) {
			throw new ValidationException($this->l10n->t('No AI provider is configured on this server'));
		}
		$customId = ($action === self::ACTION_SUMMARY ? 'summary:' . $candidate->getId() : $action);
		$task = new Task(
			TextToText::ID,
			['input' => $this->prompt($action, $candidate)],
			Application::APP_ID,
			$uid,
			$customId,
		);
		try {
			$this->taskProcessing->scheduleTask($task);
		} catch (\Exception $e) {
			$this->logger->warning('Could not schedule AI task: ' . $e->getMessage(), ['exception' => $e]);
			throw new ValidationException($this->l10n->t('The AI request could not be scheduled'));
		}
		return $task->getId() ?? 0;
	}

	/**
	 * @return array{status: string, output: string}
	 */
	public function taskResult(string $uid, int $taskId): array {
		try {
			$task = $this->taskProcessing->getUserTask($taskId, $uid);
		} catch (\Exception) {
			return ['status' => 'failed', 'output' => ''];
		}
		return match ($task->getStatus()) {
			Task::STATUS_SUCCESSFUL => ['status' => 'done', 'output' => trim((string)($task->getOutput()['output'] ?? ''))],
			Task::STATUS_FAILED, Task::STATUS_CANCELLED => ['status' => 'failed', 'output' => ''],
			default => ['status' => 'running', 'output' => ''],
		};
	}

	/**
	 * Called by the event listener when a recruiting task finishes:
	 * persist intake extractions and summaries on the candidate.
	 */
	public function applyTaskResult(Task $task): void {
		$customId = (string)$task->getCustomId();
		if (!preg_match('/^(intake|summary):(\d+)$/', $customId, $m)) {
			return;
		}
		try {
			$candidate = $this->candidateMapper->find((int)$m[2]);
		} catch (DoesNotExistException) {
			return;
		}
		if ($candidate->getAnonymizedAt() !== null) {
			return;
		}
		$output = trim((string)($task->getOutput()['output'] ?? ''));
		if ($output === '') {
			return;
		}

		$parsed = $this->parseJson($output);
		$summary = trim((string)($parsed['summary'] ?? ($parsed === [] ? $output : '')));
		if ($summary !== '') {
			$candidate->setAiSummary(mb_substr($summary, 0, 1000));
		}
		// Extraction fills empty fields only — a human's data always wins
		if ($m[1] === 'intake') {
			$name = trim((string)($parsed['name'] ?? ''));
			if ($name !== '' && $this->nameIsPlaceholder($candidate)) {
				$candidate->setDisplayName(mb_substr($name, 0, 250));
			}
			$email = trim((string)($parsed['email'] ?? ''));
			if ($email !== '' && $candidate->getEmail() === '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$candidate->setEmail($email);
			}
			$phone = trim((string)($parsed['phone'] ?? ''));
			if ($phone !== '' && $candidate->getPhone() === '' && preg_match('/^[0-9 +\-().\/]{5,32}$/', $phone)) {
				$candidate->setPhone($phone);
			}
		}
		$this->candidateMapper->update($candidate);
	}

	/**
	 * True when nothing better than a fallback is on file — the address, or
	 * the "Unknown applicant" placeholder ingestion uses when the From header
	 * carries no name. A name a human touched is never overwritten.
	 */
	private function nameIsPlaceholder(Candidate $candidate): bool {
		$name = $candidate->getDisplayName();
		return $name === ''
			|| $name === $candidate->getEmail()
			|| $name === CandidateService::UNKNOWN_APPLICANT;
	}

	private function prompt(string $action, Candidate $candidate): string {
		$opening = $this->opening($candidate);
		$openingTitle = $opening?->getTitle() ?? '';
		$requirements = trim((string)($opening?->getRequirements() ?? ''));
		$material = $this->material($candidate);

		return match ($action) {
			self::ACTION_SUMMARY => "Summarize this job application in at most 3 sentences for a hiring manager: who the applicant is, their key skills and their experience. Answer with ONLY a JSON object: {\"summary\": \"<text>\"}.\n\nPosition: $openingTitle\n\nApplication:\n$material",
			self::ACTION_HINT => "You are assisting a hiring team. Compare the candidate below against the job requirements. Give a short, factual assessment (max 5 bullet points): which requirements they likely meet, which are unclear or missing. Do not give a hire/no-hire verdict — the humans decide.\n\nPosition: $openingTitle\nRequirements:\n$requirements\n\nCandidate:\n$material",
			self::ACTION_DRAFT_REJECTION => "Write a warm, respectful rejection email body (no subject line) to {$candidate->getDisplayName()} who applied for the $openingTitle position. Thank them genuinely, deliver the rejection clearly but kindly, wish them well. Keep it under 150 words, no placeholders, plain text.",
			self::ACTION_DRAFT_INVITE => "Write a friendly interview invitation email body (no subject line) to {$candidate->getDisplayName()} for the $openingTitle position. Mention that they can pick a time slot via the scheduling link, which will be inserted separately — refer to it as 'the link below'. Keep it under 120 words, plain text.",
			self::ACTION_DRAFT_OFFER => "Write an enthusiastic but professional job offer email body (no subject line) to {$candidate->getDisplayName()} for the $openingTitle position. Congratulate them, mention that the formal offer letter follows separately, invite questions. Keep it under 150 words, no placeholders, plain text.",
			self::ACTION_QUESTIONS => "Suggest 8 concrete interview questions for the $openingTitle position, grounded in these requirements:\n$requirements\n\nCandidate background:\n$material\n\nMix technical depth and practical experience; one question per line, no numbering commentary.",
			default => throw new ValidationException($this->l10n->t('Unknown AI action')),
		};
	}

	/**
	 * The candidate material a prompt can use: profile plus the original
	 * application mail.
	 */
	private function material(Candidate $candidate): string {
		$parts = [
			'Name: ' . $candidate->getDisplayName(),
			$candidate->getEmail() !== '' ? 'Email: ' . $candidate->getEmail() : '',
			$candidate->getPhone() !== '' ? 'Phone: ' . $candidate->getPhone() : '',
		];
		foreach ($this->eventMapper->findForCandidate($candidate->getId()) as $event) {
			if ($event->getType() === TimelineEvent::TYPE_MAIL_RECEIVED) {
				$data = $event->getDecodedData();
				$parts[] = 'Application subject: ' . (string)($data['subject'] ?? '');
				$parts[] = "Application text:\n" . (string)($data['body'] ?? '');
				break;
			}
		}
		return mb_substr(implode("\n", array_filter($parts)), 0, self::MATERIAL_LIMIT);
	}

	private function parseJson(string $output): array {
		$cleaned = trim((string)preg_replace('/^```(?:json)?|```$/m', '', $output));
		$start = strpos($cleaned, '{');
		$end = strrpos($cleaned, '}');
		if ($start === false || $end === false || $end <= $start) {
			return [];
		}
		$decoded = json_decode(substr($cleaned, $start, $end - $start + 1), true);
		return is_array($decoded) ? $decoded : [];
	}

	private function opening(Candidate $candidate): ?\OCA\Recruiting\Db\Opening {
		if ($candidate->getOpeningId() === null) {
			return null;
		}
		try {
			return $this->openingMapper->find($candidate->getOpeningId());
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
