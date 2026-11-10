<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Employee referrals: any logged-in user can put a person forward for an
 * open opening. The referrer sees their own referrals with the coarse
 * status only and gains no access to the candidate itself.
 */
class ReferralService {
	public const NOTE_LIMIT = 5000;

	public function __construct(
		private ConfigService $config,
		private OpeningMapper $openingMapper,
		private CandidateMapper $candidateMapper,
		private CandidateService $candidates,
		private DocumentService $documents,
		private TimelineService $timeline,
		private IL10N $l10n,
	) {
	}

	/**
	 * Openings that accept referrals right now: open ones only, and only
	 * their id and title.
	 *
	 * @return list<array{id: int, title: string}>
	 * @throws NotPermittedException
	 */
	public function openings(): array {
		$this->assertEnabled();
		$result = [];
		foreach ($this->openingMapper->findAll() as $opening) {
			if ($opening->getStatus() === Opening::STATUS_OPEN) {
				$result[] = ['id' => $opening->getId(), 'title' => $opening->getTitle()];
			}
		}
		return $result;
	}

	/**
	 * Create the candidate in the chosen opening with source "referral".
	 * Everything is validated before anything is written.
	 *
	 * @param array{name?: string, mime?: string, content?: string}|null $cv
	 * @return array{id: int, name: string, openingTitle: string, status: string, createdAt: string}
	 * @throws NotPermittedException|ValidationException
	 */
	public function create(string $uid, array $data, ?array $cv): array {
		$this->assertEnabled();

		$openingId = (int)($data['openingId'] ?? 0);
		try {
			$opening = $this->openingMapper->find($openingId);
		} catch (DoesNotExistException) {
			throw new ValidationException($this->l10n->t('Please pick an open position'));
		}
		if ($opening->getStatus() !== Opening::STATUS_OPEN) {
			throw new ValidationException($this->l10n->t('Please pick an open position'));
		}

		$name = trim((string)($data['name'] ?? ''));
		$email = trim((string)($data['email'] ?? ''));
		$phone = trim((string)($data['phone'] ?? ''));
		$note = trim((string)($data['note'] ?? ''));
		if ($name === '') {
			throw new ValidationException($this->l10n->t('A name is required'));
		}
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new ValidationException($this->l10n->t('The email address is invalid'));
		}
		if ($phone !== '' && !preg_match('/^[0-9 +\-().\/]{3,60}$/', $phone)) {
			throw new ValidationException($this->l10n->t('The phone number is invalid'));
		}
		if ($note === '') {
			throw new ValidationException($this->l10n->t('Please add a note why you recommend this person'));
		}
		if (mb_strlen($note) > self::NOTE_LIMIT) {
			throw new ValidationException($this->l10n->t('The note is too long'));
		}
		if (!$this->isTruthy($data['consent'] ?? null)) {
			throw new ValidationException($this->l10n->t('Please confirm that this person agreed to be referred'));
		}
		$cvMime = null;
		if ($cv !== null) {
			$cvMime = $this->documents->validate((string)($cv['mime'] ?? ''), (string)($cv['content'] ?? ''));
		}

		$candidate = $this->candidates->insertCandidate(
			$opening->getId(),
			$name,
			$email,
			$phone,
			Candidate::SOURCE_REFERRAL,
			$uid,
			$uid,
		);
		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_REFERRAL_NOTE, $uid, ['note' => $note]);
		if ($cv !== null && $cvMime !== null) {
			$document = $this->documents->add($candidate, (string)($cv['name'] ?? 'CV'), $cvMime, (string)($cv['content'] ?? ''), $uid);
			$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_DOCUMENT_ADDED, $uid, ['name' => $document->getName()]);
		}
		return $this->serialize($candidate, $opening->getTitle());
	}

	/**
	 * The referrer's own referrals: who, for which opening, and the coarse
	 * status — nothing the hiring team wrote or decided in detail.
	 *
	 * @return list<array{id: int, name: string, openingTitle: string, status: string, createdAt: string}>
	 * @throws NotPermittedException
	 */
	public function listMine(string $uid): array {
		$this->assertEnabled();
		$titles = [];
		$result = [];
		foreach ($this->candidateMapper->findByReferrer($uid) as $candidate) {
			$openingId = $candidate->getOpeningId() ?? 0;
			$titles[$openingId] ??= $this->openingMapper->findTitle($candidate->getOpeningId()) ?? '';
			$result[] = $this->serialize($candidate, $titles[$openingId]);
		}
		return $result;
	}

	/**
	 * @return array{id: int, name: string, openingTitle: string, status: string, createdAt: string}
	 */
	private function serialize(Candidate $candidate, string $openingTitle): array {
		return [
			'id' => $candidate->getId(),
			'name' => $candidate->getDisplayName(),
			'openingTitle' => $openingTitle,
			'status' => CandidateStatus::of($candidate),
			'createdAt' => $candidate->getCreatedAt()->format(\DateTimeInterface::ATOM),
		];
	}

	/**
	 * @throws NotPermittedException
	 */
	private function assertEnabled(): void {
		if (!$this->config->isReferralsEnabled()) {
			throw new NotPermittedException($this->l10n->t('Referrals are turned off on this server'));
		}
	}

	private function isTruthy(mixed $value): bool {
		return $value === true || $value === 1 || in_array($value, ['1', 'true', 'on', 'yes'], true);
	}
}
