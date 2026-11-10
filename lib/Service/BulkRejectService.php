<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Reject many candidates at once with one shared decision (reason, mail
 * template, talent-pool ask) — the bulk bar and the "close opening" step.
 *
 * The mail text is a template with placeholders, rendered per candidate.
 * Every candidate is handled on its own: permission is checked for each one
 * before acting, and a failure (no access, mail not sent, already rejected)
 * is collected instead of aborting the rest.
 */
class BulkRejectService {
	/** The client splits larger selections, so no request runs too long. */
	public const MAX_PER_REQUEST = 50;

	public function __construct(
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private CandidateService $candidates,
		private MailService $mail,
		private PermissionService $permissions,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<array-key, mixed> $ids candidate ids
	 * @return array{rejected: list<array{id: int, name: string, mailed: bool}>, failed: list<array{id: int, name: string, message: string}>}
	 * @throws ValidationException for input that makes the whole request invalid
	 */
	public function reject(string $uid, array $ids, string $reason, bool $sendMail, string $subject, string $body, bool $askPool): array {
		$ids = array_values(array_unique(array_filter(
			array_map(static fn ($id) => (int)$id, $ids),
			static fn (int $id) => $id > 0,
		)));
		if ($ids === []) {
			throw new ValidationException($this->l10n->t('Select at least one candidate'));
		}
		if (count($ids) > self::MAX_PER_REQUEST) {
			throw new ValidationException($this->l10n->t('At most %d candidates can be rejected per request', [self::MAX_PER_REQUEST]));
		}
		if (!in_array($reason, Candidate::REJECTION_REASONS, true)) {
			throw new ValidationException($this->l10n->t('Invalid rejection reason'));
		}
		if ($sendMail && (trim($subject) === '' || trim($body) === '')) {
			throw new ValidationException($this->l10n->t('Subject and message must not be empty'));
		}

		$found = [];
		foreach ($this->candidateMapper->findByIds($ids) as $candidate) {
			$found[$candidate->getId()] = $candidate;
		}

		$rejected = [];
		$failed = [];
		/** @var array<int, ?string> $titles */
		$titles = [];
		foreach ($ids as $id) {
			$candidate = $found[$id] ?? null;
			if ($candidate === null) {
				$failed[] = ['id' => $id, 'name' => '', 'message' => $this->l10n->t('Not found')];
				continue;
			}
			try {
				$this->permissions->assertCanManageCandidate($uid, $candidate);
				$mailed = $sendMail && $candidate->getEmail() !== '';
				$mailSubject = null;
				$mailBody = null;
				if ($mailed) {
					$context = $this->mail->contextFor($candidate, $this->openingTitle($candidate, $titles), $uid);
					$mailSubject = $this->mail->render($subject, $context);
					$mailBody = $this->mail->render($body, $context);
				}
				$this->candidates->rejectCandidate($uid, $candidate, $reason, $mailSubject, $mailBody, $mailed && $askPool);
				$rejected[] = ['id' => $id, 'name' => $candidate->getDisplayName(), 'mailed' => $mailed];
			} catch (ValidationException|NotPermittedException $e) {
				$failed[] = ['id' => $id, 'name' => $this->visibleName($uid, $candidate), 'message' => $e->getMessage()];
			} catch (\Exception $e) {
				$this->logger->error('Recruiting: bulk rejection failed for a candidate', ['exception' => $e, 'app' => 'recruiting']);
				$failed[] = ['id' => $id, 'name' => $this->visibleName($uid, $candidate), 'message' => $this->l10n->t('An unexpected error occurred.')];
			}
		}
		return ['rejected' => $rejected, 'failed' => $failed];
	}

	/**
	 * @param array<int, ?string> $titles per-request cache, keyed by opening id
	 */
	private function openingTitle(Candidate $candidate, array &$titles): ?string {
		$openingId = $candidate->getOpeningId();
		if ($openingId === null) {
			return null;
		}
		if (!array_key_exists($openingId, $titles)) {
			$titles[$openingId] = $this->openingMapper->findTitle($openingId);
		}
		return $titles[$openingId];
	}

	/**
	 * A failure report must not reveal the name of a candidate the user may
	 * not see at all.
	 */
	private function visibleName(string $uid, Candidate $candidate): string {
		return $this->permissions->canViewCandidate($uid, $candidate) ? $candidate->getDisplayName() : '';
	}
}
