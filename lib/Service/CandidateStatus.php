<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCP\IL10N;

/**
 * The coarse status shown outside the hiring team — to the candidate on the
 * self-service page and to an employee who referred them. Derived from the
 * stage alone and deliberately vague: no internal stage names, votes or
 * rejection reasons, and "closed" covers hired, rejected and withdrawn alike.
 */
final class CandidateStatus {
	public const RECEIVED = 'received';
	public const IN_REVIEW = 'in_review';
	public const INTERVIEW = 'interview';
	public const OFFER = 'offer';
	public const CLOSED = 'closed';

	public static function forStage(string $stage): string {
		return match ($stage) {
			Candidate::STAGE_NEW => self::RECEIVED,
			Candidate::STAGE_SCREENING => self::IN_REVIEW,
			Candidate::STAGE_INTERVIEW => self::INTERVIEW,
			Candidate::STAGE_OFFER => self::OFFER,
			Candidate::STAGE_HIRED, Candidate::STAGE_REJECTED, Candidate::STAGE_WITHDRAWN => self::CLOSED,
			default => self::RECEIVED,
		};
	}

	public static function of(Candidate $candidate): string {
		return self::forStage($candidate->getStage());
	}

	public static function label(string $status, IL10N $l): string {
		return match ($status) {
			self::IN_REVIEW => $l->t('In review'),
			self::INTERVIEW => $l->t('Interview scheduled'),
			self::OFFER => $l->t('Offer'),
			self::CLOSED => $l->t('Closed'),
			default => $l->t('Received'),
		};
	}

	/**
	 * One sentence for the candidate explaining the status.
	 */
	public static function explanation(string $status, IL10N $l): string {
		return match ($status) {
			self::IN_REVIEW => $l->t('The hiring team is reviewing your application.'),
			self::INTERVIEW => $l->t('You are in the interview phase. Your interview appointments are listed below.'),
			self::OFFER => $l->t('We are preparing or have sent you an offer.'),
			self::CLOSED => $l->t('The application process has ended.'),
			default => $l->t('We have received your application and will review it soon.'),
		};
	}
}
