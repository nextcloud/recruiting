<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * The per-candidate discussion thread, backed by OCP\Comments (spec §3) —
 * mention parsing comes for free, and @-mentions become notifications.
 */
class CommentService {
	public const OBJECT_TYPE = 'recruiting_candidate';

	public function __construct(
		private ICommentsManager $commentsManager,
		private CandidateMapper $candidateMapper,
		private PermissionService $permissions,
		private NotificationService $notifications,
		private IUserManager $userManager,
		private IL10N $l10n,
	) {
	}

	/**
	 * @throws NotPermittedException
	 */
	public function listFor(string $uid, int $candidateId): array {
		$candidate = $this->candidateMapper->find($candidateId);
		$this->permissions->assertCanViewCandidate($uid, $candidate);

		$comments = $this->commentsManager->getForObject(self::OBJECT_TYPE, (string)$candidateId);
		// getForObject returns newest first — the thread reads top-down
		return array_reverse(array_map(fn (IComment $c) => $this->serialize($c), $comments));
	}

	/**
	 * @throws NotPermittedException|ValidationException
	 */
	public function create(string $uid, int $candidateId, string $message): array {
		$candidate = $this->candidateMapper->find($candidateId);
		if (!$this->permissions->canComment($uid, $candidate)) {
			throw new NotPermittedException('Not allowed to comment on this candidate');
		}
		$message = trim($message);
		if ($message === '') {
			throw new ValidationException($this->l10n->t('The comment must not be empty'));
		}
		if (mb_strlen($message) > 4000) {
			throw new ValidationException($this->l10n->t('The comment is too long'));
		}

		$comment = $this->commentsManager->create('users', $uid, self::OBJECT_TYPE, (string)$candidateId);
		$comment->setMessage($message);
		$comment->setVerb('comment');
		$this->commentsManager->save($comment);

		$this->notifyMentions($comment, $candidate, $uid);
		return $this->serialize($comment);
	}

	private function notifyMentions(IComment $comment, Candidate $candidate, string $authorUid): void {
		$mentioned = [];
		foreach ($comment->getMentions() as $mention) {
			if ($mention['type'] !== 'user') {
				continue;
			}
			$uid = $mention['id'];
			// Only notify people who are actually allowed to see the candidate
			if ($uid !== $authorUid
				&& $this->userManager->get($uid) !== null
				&& $this->permissions->canViewCandidate($uid, $candidate)) {
				$mentioned[] = $uid;
			}
		}
		if ($mentioned !== []) {
			$this->notifications->notify($mentioned, NotificationService::SUBJECT_MENTION, $candidate, $authorUid);
		}
	}

	private function serialize(IComment $comment): array {
		$authorUid = $comment->getActorId();
		return [
			'id' => (int)$comment->getId(),
			'authorUid' => $authorUid,
			'authorDisplayName' => $this->userManager->getDisplayName($authorUid) ?? $authorUid,
			'message' => $comment->getMessage(),
			'createdAt' => $comment->getCreationDateTime()->format(\DateTimeInterface::ATOM),
		];
	}
}
