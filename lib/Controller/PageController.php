<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\SessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IInitialState $initialState,
		private SessionService $sessionService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Render the single-page app.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('session', $this->sessionService->getSessionInfo());

		Util::addScript($this->appName, 'recruiting-main');

		$response = new TemplateResponse($this->appName, 'main');
		$response->setContentSecurityPolicy(new ContentSecurityPolicy());
		return $response;
	}

	/**
	 * The user handbook (PDF), linked from the app navigation. Served
	 * through a controller — not as a static file — because not every
	 * webserver config allows .pdf from an app directory.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function handbook(): DataDisplayResponse|DataResponse {
		$path = __DIR__ . '/../../docs/handbook.pdf';
		$pdf = is_file($path) ? file_get_contents($path) : false;
		if ($pdf === false) {
			return new DataResponse(['message' => 'Handbook not available'], Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse($pdf, Http::STATUS_OK, ['Content-Type' => 'application/pdf']);
		$response->addHeader('Content-Disposition', 'inline; filename="recruiting-handbook.pdf"');
		$response->cacheFor(3600);
		return $response;
	}
}
