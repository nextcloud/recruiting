<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;

/**
 * A mail written to a candidate from the sidebar ("Write email"): free
 * text with placeholders, sent like every other candidate mail — with the
 * candidate's Reply-To token and logged on the timeline.
 */
class ComposeService {
	public function __construct(
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private PermissionService $permissions,
		private MailService $mail,
		private TemplateService $templates,
	) {
	}

	/**
	 * @throws NotPermittedException|ValidationException
	 */
	public function writeEmail(string $uid, int $candidateId, string $subject, string $body): void {
		$candidate = $this->candidateMapper->find($candidateId);
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		$this->templates->assertValidPlaceholders($subject);
		$this->templates->assertValidPlaceholders($body);

		$context = $this->mail->contextFor($candidate, $this->openingMapper->findTitle($candidate->getOpeningId()), $uid);
		$this->mail->sendToCandidate(
			$candidate,
			$this->mail->render($subject, $context),
			$this->mail->render($body, $context),
			$uid,
			MailTemplate::TYPE_CUSTOM,
		);
	}
}
