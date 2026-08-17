<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class MailController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private MailService $mail,
		private CandidateService $candidates,
		private PermissionService $permissions,
		private OpeningMapper $openingMapper,
		private OfferMapper $offerMapper,
		private \OCP\IL10N $l10n,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Rendered preview for the compose dialog.
	 */
	#[NoAdminRequired]
	public function preview(int $candidateId, string $type, ?string $templateId = null): DataResponse {
		return $this->handle(function () use ($candidateId, $type, $templateId) {
			$uid = $this->uid();
			$candidate = $this->candidates->find($candidateId);
			$this->permissions->assertCanManageCandidate($uid, $candidate);

			$openingTitle = null;
			if ($candidate->getOpeningId() !== null) {
				try {
					$openingTitle = $this->openingMapper->find($candidate->getOpeningId())->getTitle();
				} catch (DoesNotExistException) {
				}
			}

			// Offer mails resolve their placeholders from the latest offer
			$extra = [];
			if ($type === MailTemplate::TYPE_OFFER) {
				$offers = $this->offerMapper->findForCandidate($candidateId);
				$offer = $offers[0] ?? null;
				if ($offer !== null) {
					$extra = [
						'offer_job_title' => $offer->getJobTitle(),
						'offer_start_date' => $offer->getStartDate() !== null ? (string)$this->l10n->l('date', $offer->getStartDate()) : '',
						'offer_valid_until' => $offer->getValidUntil() !== null ? (string)$this->l10n->l('date', $offer->getValidUntil()) : '',
					];
				}
			}
			return $this->mail->preview($candidate, $openingTitle, $type, $templateId, $uid, $extra);
		});
	}
}
