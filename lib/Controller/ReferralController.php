<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\ReferralService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IMimeTypeDetector;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Employee referrals — open to every logged-in user, no recruiting role
 * needed.
 */
class ReferralController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ReferralService $referrals,
		private IMimeTypeDetector $mimeDetector,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function openings(): DataResponse {
		return $this->handle(fn () => $this->referrals->openings());
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->handle(fn () => $this->referrals->listMine($this->uid()));
	}

	/**
	 * Multipart form: openingId, name, email, phone, note, consent and an
	 * optional "cv" file.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 3600)]
	public function create(): DataResponse {
		return $this->handle(function () {
			$cv = null;
			$file = $this->request->getUploadedFile('cv');
			if (is_array($file) && ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_NO_FILE) {
				if (($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
					throw new ValidationException($this->l10n->t('The uploaded file could not be read.'));
				}
				$path = (string)($file['tmp_name'] ?? '');
				$content = @file_get_contents($path);
				if ($content === false) {
					throw new ValidationException($this->l10n->t('The uploaded file could not be read.'));
				}
				$mime = $this->mimeDetector->detect($path);
				if ($mime === 'application/octet-stream') {
					$mime = $this->mimeDetector->detectPath((string)($file['name'] ?? ''));
				}
				$cv = ['name' => (string)($file['name'] ?? 'CV'), 'mime' => $mime, 'content' => $content];
			}
			return $this->referrals->create($this->uid(), $this->request->getParams(), $cv);
		});
	}
}
