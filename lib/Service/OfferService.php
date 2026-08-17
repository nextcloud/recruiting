<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Offer;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * The offer process (spec §4.6): structured record, mandatory approval by a
 * designated approver, response tracking and expiry handling. Offer data is
 * only ever visible to recruiters, the opening's hiring managers and the
 * offer's approver — never to interviewers or observers.
 */
class OfferService {
	public function __construct(
		private OfferMapper $offerMapper,
		private CandidateMapper $candidateMapper,
		private PermissionService $permissions,
		private StageService $stages,
		private MailService $mail,
		private TimelineService $timeline,
		private NotificationService $notifications,
		private ActivityPublisher $activity,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return array[]
	 */
	public function listFor(Candidate $candidate): array {
		return array_map(
			fn (Offer $offer) => $this->serialize($offer),
			$this->offerMapper->findForCandidate($candidate->getId()),
		);
	}

	/**
	 * @throws NotPermittedException|ValidationException
	 */
	public function create(string $uid, int $candidateId, array $data): array {
		$candidate = $this->candidateMapper->find($candidateId);
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		if ($candidate->getOpeningId() === null) {
			throw new ValidationException($this->l10n->t('Assign the candidate to an opening first'));
		}
		if ($candidate->isTerminal()) {
			throw new ValidationException($this->l10n->t('This candidate is no longer in the running'));
		}
		if ($this->offerMapper->hasActiveOffer($candidateId)) {
			throw new ValidationException($this->l10n->t('There is already an active offer for this candidate'));
		}

		$offer = new Offer();
		$offer->setCandidateId($candidateId);
		$offer->setStatus(Offer::STATUS_DRAFT);
		$offer->setCreatedBy($uid);
		$offer->setCreatedAt($this->timeFactory->getDateTime());
		$this->applyFields($offer, $data);
		$offer = $this->offerMapper->insert($offer);

		$this->timeline->record($candidateId, TimelineEvent::TYPE_OFFER_CREATED, $uid, ['jobTitle' => $offer->getJobTitle()]);
		$this->activity->publish($candidate, 'offer_created', $uid);
		return $this->serialize($offer);
	}

	/**
	 * Offer terms are only editable while it is a draft.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function update(string $uid, int $offerId, array $data): array {
		[$offer, ] = $this->loadForManage($uid, $offerId);
		if ($offer->getStatus() !== Offer::STATUS_DRAFT) {
			throw new ValidationException($this->l10n->t('Only draft offers can be edited'));
		}
		$this->applyFields($offer, $data);
		$this->offerMapper->update($offer);
		return $this->serialize($offer);
	}

	/**
	 * Draft → pending approval. Self-approval is not allowed.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function submit(string $uid, int $offerId, string $approverUid): array {
		[$offer, $candidate] = $this->loadForManage($uid, $offerId);
		$this->assertTransition($offer, Offer::STATUS_PENDING_APPROVAL);

		$approverUid = trim($approverUid);
		if ($approverUid === '' || $this->userManager->get($approverUid) === null) {
			throw new ValidationException($this->l10n->t('Pick an approver'));
		}
		if ($approverUid === $uid) {
			throw new ValidationException($this->l10n->t('You cannot approve your own offer'));
		}
		if ($offer->getJobTitle() === '') {
			throw new ValidationException($this->l10n->t('The offer needs a job title'));
		}

		$offer->setApproverUid($approverUid);
		$offer->setStatus(Offer::STATUS_PENDING_APPROVAL);
		$this->offerMapper->update($offer);

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_OFFER_SUBMITTED, $uid, ['approver' => $approverUid]);
		$this->notifications->notify([$approverUid], NotificationService::SUBJECT_OFFER_APPROVAL_REQUESTED, $candidate, $uid);
		return $this->serialize($offer);
	}

	/**
	 * @throws NotPermittedException|ValidationException
	 */
	public function approve(string $uid, int $offerId): array {
		$offer = $this->offerMapper->find($offerId);
		$candidate = $this->candidateMapper->find($offer->getCandidateId());
		if ($offer->getApproverUid() !== $uid) {
			throw new NotPermittedException('Only the designated approver can decide on this offer');
		}
		$this->assertTransition($offer, Offer::STATUS_APPROVED);

		$offer->setStatus(Offer::STATUS_APPROVED);
		$offer->setApprovedAt($this->timeFactory->getDateTime());
		$this->offerMapper->update($offer);

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_OFFER_APPROVED, $uid);
		$this->activity->publish($candidate, 'offer_approved', $uid);
		$this->notifications->notify([$offer->getCreatedBy()], NotificationService::SUBJECT_OFFER_APPROVED, $candidate, $uid);
		return $this->serialize($offer);
	}

	/**
	 * Approver sends the offer back to draft, with a word why.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function declineApproval(string $uid, int $offerId, string $note): array {
		$offer = $this->offerMapper->find($offerId);
		$candidate = $this->candidateMapper->find($offer->getCandidateId());
		if ($offer->getApproverUid() !== $uid) {
			throw new NotPermittedException('Only the designated approver can decide on this offer');
		}
		$this->assertTransition($offer, Offer::STATUS_DRAFT);

		$offer->setStatus(Offer::STATUS_DRAFT);
		$offer->setApproverUid(null);
		$this->offerMapper->update($offer);

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_OFFER_APPROVAL_DECLINED, $uid, ['note' => trim($note)]);
		$this->notifications->notify([$offer->getCreatedBy()], NotificationService::SUBJECT_OFFER_APPROVAL_DECLINED, $candidate, $uid);
		return $this->serialize($offer);
	}

	/**
	 * Approved → sent, optionally mailing the (previewed) offer to the
	 * candidate. The pipeline follows: the card moves to the Offer column.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function send(string $uid, int $offerId, ?string $mailSubject, ?string $mailBody): array {
		[$offer, $candidate] = $this->loadForManage($uid, $offerId);
		$this->assertTransition($offer, Offer::STATUS_SENT);

		if ($mailSubject !== null && $mailBody !== null) {
			$this->mail->sendToCandidate($candidate, $mailSubject, $mailBody, $uid);
		}

		$offer->setStatus(Offer::STATUS_SENT);
		$this->offerMapper->update($offer);

		// No-op when the candidate is already in Offer or has finished
		$this->stages->apply($candidate, Candidate::STAGE_OFFER, $uid);
		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_OFFER_SENT, $uid);
		$this->activity->publish($candidate, 'offer_sent', $uid);
		return $this->serialize($offer);
	}

	/**
	 * Record the candidate's response. Accepting hires the candidate.
	 *
	 * @throws NotPermittedException|ValidationException
	 */
	public function respond(string $uid, int $offerId, string $response): array {
		[$offer, $candidate] = $this->loadForManage($uid, $offerId);
		if (!in_array($response, [Offer::STATUS_ACCEPTED, Offer::STATUS_DECLINED, Offer::STATUS_NEGOTIATING], true)) {
			throw new ValidationException($this->l10n->t('Invalid response'));
		}
		$this->assertTransition($offer, $response);
		// Hiring somebody who was rejected or withdrew in the meantime is a
		// conflict only a human can resolve — never resurrect them silently.
		if ($response === Offer::STATUS_ACCEPTED && $candidate->isTerminal()) {
			throw new ValidationException($this->l10n->t('This candidate is no longer in the running. Move them back into the pipeline first, then record the acceptance.'));
		}

		$offer->setStatus($response);
		$offer->setRespondedAt($this->timeFactory->getDateTime());
		$this->offerMapper->update($offer);

		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_OFFER_RESPONSE, $uid, ['response' => $response]);
		if ($response === Offer::STATUS_ACCEPTED) {
			$this->stages->apply($candidate, Candidate::STAGE_HIRED, $uid);
			$this->activity->publish($candidate, 'offer_accepted', $uid);
		} elseif ($response === Offer::STATUS_DECLINED) {
			$this->activity->publish($candidate, 'offer_declined', $uid);
		}
		return $this->serialize($offer);
	}

	/**
	 * @throws NotPermittedException|ValidationException
	 */
	public function withdraw(string $uid, int $offerId): array {
		[$offer, $candidate] = $this->loadForManage($uid, $offerId);
		$this->assertTransition($offer, Offer::STATUS_WITHDRAWN);
		$offer->setStatus(Offer::STATUS_WITHDRAWN);
		$this->offerMapper->update($offer);
		$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_OFFER_WITHDRAWN, $uid);
		return $this->serialize($offer);
	}

	/**
	 * Daily housekeeping (spec §4.6): notify creators of offers expiring
	 * within three days, mark overdue offers expired.
	 *
	 * @return array{expired: int, notified: int}
	 */
	public function processExpiry(): array {
		$today = \DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime())->setTime(0, 0);
		$soon = $today->modify('+3 days');
		$expired = 0;
		$notified = 0;

		foreach ($this->offerMapper->findOpen() as $offer) {
			$validUntil = $offer->getValidUntil();
			if ($validUntil === null) {
				continue;
			}
			$validDay = \DateTimeImmutable::createFromInterface($validUntil)->setTime(0, 0);
			try {
				$candidate = $this->candidateMapper->find($offer->getCandidateId());
			} catch (\OCP\AppFramework\Db\DoesNotExistException) {
				continue;
			}

			if ($validDay < $today) {
				$offer->setStatus(Offer::STATUS_EXPIRED);
				$this->offerMapper->update($offer);
				$this->timeline->record($candidate->getId(), TimelineEvent::TYPE_OFFER_EXPIRED, null);
				$this->activity->publish($candidate, 'offer_expired', null);
				$this->notifications->notify([$offer->getCreatedBy()], NotificationService::SUBJECT_OFFER_EXPIRED, $candidate, null);
				$expired++;
			} elseif ($validDay <= $soon && !$offer->getExpiryNotified()) {
				$offer->setExpiryNotified(true);
				$this->offerMapper->update($offer);
				$this->notifications->notify([$offer->getCreatedBy()], NotificationService::SUBJECT_OFFER_EXPIRING, $candidate, null, [
					'validUntil' => $validDay->format('Y-m-d'),
				]);
				$notified++;
			}
		}
		return ['expired' => $expired, 'notified' => $notified];
	}

	public function serialize(Offer $offer): array {
		$data = $offer->jsonSerialize();
		$data['createdByDisplayName'] = $this->displayName($offer->getCreatedBy());
		$data['approverDisplayName'] = $offer->getApproverUid() !== null ? $this->displayName($offer->getApproverUid()) : null;
		return $data;
	}

	/**
	 * @return array{0: Offer, 1: Candidate}
	 * @throws NotPermittedException
	 */
	private function loadForManage(string $uid, int $offerId): array {
		$offer = $this->offerMapper->find($offerId);
		$candidate = $this->candidateMapper->find($offer->getCandidateId());
		$this->permissions->assertCanManageCandidate($uid, $candidate);
		return [$offer, $candidate];
	}

	/**
	 * @throws ValidationException
	 */
	private function assertTransition(Offer $offer, string $to): void {
		if (!$offer->canTransitionTo($to)) {
			throw new ValidationException($this->l10n->t('This offer cannot change from "%1$s" to "%2$s"', [$offer->getStatus(), $to]));
		}
	}

	/**
	 * @throws ValidationException
	 */
	private function applyFields(Offer $offer, array $data): void {
		if (array_key_exists('jobTitle', $data)) {
			$title = trim((string)$data['jobTitle']);
			if ($title === '') {
				throw new ValidationException($this->l10n->t('The offer needs a job title'));
			}
			$offer->setJobTitle($title);
		}
		if (array_key_exists('salaryAmount', $data)) {
			$amount = trim((string)$data['salaryAmount']);
			if ($amount !== '' && !preg_match('/^\d{1,12}([.,]\d{1,2})?$/', $amount)) {
				throw new ValidationException($this->l10n->t('Invalid salary amount'));
			}
			$offer->setSalaryAmount($amount);
		}
		if (array_key_exists('salaryCurrency', $data)) {
			$currency = strtoupper(trim((string)$data['salaryCurrency']));
			if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
				throw new ValidationException($this->l10n->t('Invalid currency'));
			}
			$offer->setSalaryCurrency($currency !== '' ? $currency : 'EUR');
		}
		if (array_key_exists('salaryPeriod', $data)) {
			$period = (string)$data['salaryPeriod'];
			if (!in_array($period, ['year', 'month', 'hour'], true)) {
				throw new ValidationException($this->l10n->t('Invalid salary period'));
			}
			$offer->setSalaryPeriod($period);
		}
		foreach (['startDate' => 'setStartDate', 'validUntil' => 'setValidUntil'] as $key => $setter) {
			if (array_key_exists($key, $data)) {
				$value = trim((string)($data[$key] ?? ''));
				if ($value === '') {
					$offer->$setter(null);
					continue;
				}
				$date = \DateTime::createFromFormat('Y-m-d', $value);
				if ($date === false) {
					throw new ValidationException($this->l10n->t('Invalid date'));
				}
				$offer->$setter($date->setTime(0, 0));
			}
		}
		if (array_key_exists('notes', $data)) {
			$offer->setNotes((string)$data['notes']);
		}
	}

	private function displayName(string $uid): string {
		return $this->userManager->getDisplayName($uid) ?? $uid;
	}
}
