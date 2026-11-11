<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\ComposeService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\TemplateService;
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
		private TemplateService $templates,
		private \OCP\IL10N $l10n,
		private IUserSession $userSession,
		private InterviewMapper $interviewMapper,
		private InterviewService $interviews,
		private ComposeService $compose,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Rendered preview for the compose dialog, plus the unrendered template
	 * text (`template`) for dialogs that send one text to many candidates
	 * and the templates of this type to choose from (`templates`). Without
	 * $templateId, the default for the candidate's language is used.
	 * With $subject and $body, that text is rendered for this candidate
	 * instead of the stored template. $interviewId fills the interview
	 * placeholders from one of the candidate's interviews.
	 */
	#[NoAdminRequired]
	public function preview(int $candidateId, string $type, ?string $templateId = null, ?string $subject = null, ?string $body = null, ?int $interviewId = null): DataResponse {
		return $this->handle(function () use ($candidateId, $type, $templateId, $subject, $body, $interviewId) {
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
					$l = $this->mail->l10nFor($candidate);
					$extra = [
						'offer_job_title' => $offer->getJobTitle(),
						'offer_start_date' => $offer->getStartDate() !== null ? (string)$l->l('date', $offer->getStartDate()) : '',
						'offer_valid_until' => $offer->getValidUntil() !== null ? (string)$l->l('date', $offer->getValidUntil()) : '',
					];
				}
			}
			if ($interviewId !== null) {
				$interview = $this->interviewMapper->find($interviewId);
				if ($interview->getCandidateId() !== $candidate->getId()) {
					throw new NotPermittedException($this->l10n->t('This interview belongs to another candidate'));
				}
				$extra = array_merge($extra, $this->interviews->invitePlaceholders($interview));
			}
			if ($subject !== null && $body !== null) {
				$context = $this->mail->contextFor($candidate, $openingTitle, $uid, $extra);
				return [
					'subject' => $this->mail->render($subject, $context),
					'body' => $this->mail->render($body, $context),
					'to' => $candidate->getEmail(),
				];
			}
			$preview = $this->mail->preview($candidate, $openingTitle, $type, $templateId, $uid, $extra);
			$preview['template'] = $this->templates->resolve($type, $templateId, $candidate->getLanguage());
			$preview['templates'] = $this->templates->listForType($type);
			return $preview;
		});
	}

	/**
	 * Send a mail written in the sidebar ("Write email").
	 */
	#[NoAdminRequired]
	public function send(int $candidateId, string $subject, string $body): DataResponse {
		return $this->handle(function () use ($candidateId, $subject, $body) {
			$this->compose->writeEmail($this->uid(), $candidateId, $subject, $body);
			return ['sent' => true];
		});
	}
}
