<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\PortalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The candidate self-service page at /portal/{token}: server-rendered
 * forms, no scripts. The token is the only credential, so every action is
 * brute-force protected and rate limited, and an unknown, cleared or
 * anonymized token gets the same neutral 404.
 */
class PortalController extends Controller {
	private const THROTTLE_ACTION = 'recruitingPortal';

	public function __construct(
		string $appName,
		IRequest $request,
		private PortalService $portal,
		private Defaults $defaults,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function show(string $token): TemplateResponse {
		$candidate = $this->portal->findByToken($token);
		if ($candidate === null) {
			return $this->notFound();
		}
		return $this->page($candidate, $token);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	#[AnonRateLimit(limit: 5, period: 60)]
	public function withdraw(string $token): TemplateResponse {
		$candidate = $this->portal->findByToken($token);
		if ($candidate === null) {
			return $this->notFound();
		}
		if (!$this->confirmed()) {
			return $this->page($candidate, $token, errors: [$this->l10n->t('Please tick the box to confirm that you want to withdraw.')]);
		}
		try {
			$this->portal->withdraw($candidate);
		} catch (ValidationException $e) {
			return $this->page($candidate, $token, errors: [$e->getMessage()]);
		}
		return $this->page($candidate, $token, notice: $this->l10n->t('Your application has been withdrawn. Thank you for your interest.'));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	#[AnonRateLimit(limit: 5, period: 60)]
	public function requestDeletion(string $token): TemplateResponse {
		$candidate = $this->portal->findByToken($token);
		if ($candidate === null) {
			return $this->notFound();
		}
		if (!$this->confirmed()) {
			return $this->page($candidate, $token, errors: [$this->l10n->t('Please tick the box to confirm your deletion request.')]);
		}
		$this->portal->requestDeletion($candidate);
		return $this->page($candidate, $token, notice: $this->l10n->t('Your deletion request has been received. The recruiting team will delete your data.'));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	#[AnonRateLimit(limit: 5, period: 60)]
	public function uploadDocument(string $token): TemplateResponse {
		$candidate = $this->portal->findByToken($token);
		if ($candidate === null) {
			return $this->notFound();
		}
		$file = $this->request->getUploadedFile('document');
		if (!is_array($file) || ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
			return $this->page($candidate, $token, errors: [$this->l10n->t('Please choose a file to upload.')]);
		}
		$content = @file_get_contents((string)($file['tmp_name'] ?? ''));
		if ($content === false) {
			return $this->page($candidate, $token, errors: [$this->l10n->t('The uploaded file could not be read.')]);
		}
		try {
			$this->portal->addDocument(
				$candidate,
				(string)($file['name'] ?? 'document'),
				(string)($file['type'] ?? 'application/octet-stream'),
				$content,
			);
		} catch (ValidationException $e) {
			return $this->page($candidate, $token, errors: [$e->getMessage()]);
		} catch (\Throwable $e) {
			$this->logger->error('Recruiting: portal upload failed', ['exception' => $e]);
			return $this->page($candidate, $token, errors: [$this->l10n->t('Something went wrong. Please try again.')]);
		}
		return $this->page($candidate, $token, notice: $this->l10n->t('Thank you, your document has been added to your application.'));
	}

	private function confirmed(): bool {
		$value = $this->request->getParam('confirm', '');
		return $value === 'on' || $value === '1';
	}

	/**
	 * @param string[] $errors
	 */
	private function page(Candidate $candidate, string $token, ?string $notice = null, array $errors = []): TemplateResponse {
		$response = new TemplateResponse($this->appName, 'public-portal', [
			'notFound' => false,
			'token' => $token,
			'view' => $this->portal->view($candidate),
			'notice' => $notice,
			'errors' => $errors,
			'instanceName' => $this->defaults->getName(),
		], TemplateResponse::RENDER_AS_GUEST);
		if ($errors !== []) {
			$response->setStatus(Http::STATUS_BAD_REQUEST);
		}
		return $response;
	}

	private function notFound(): TemplateResponse {
		$response = new TemplateResponse($this->appName, 'public-portal', [
			'notFound' => true,
			'instanceName' => $this->defaults->getName(),
		], TemplateResponse::RENDER_AS_GUEST);
		$response->setStatus(Http::STATUS_NOT_FOUND);
		$response->throttle(['action' => self::THROTTLE_ACTION]);
		return $response;
	}
}
