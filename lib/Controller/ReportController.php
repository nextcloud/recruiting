<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\ReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

class ReportController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ReportService $reports,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param ?string $from first day (Y-m-d), default 12 months before $to
	 * @param ?string $to last day (Y-m-d), default today
	 */
	#[NoAdminRequired]
	public function overview(?string $from = null, ?string $to = null): DataResponse {
		return $this->handle(fn () => $this->reports->overview($this->uid(), $from, $to));
	}

	/**
	 * The per-opening table as CSV. Downloaded through a plain link (no
	 * request token), so CSRF protection is not required — the endpoint
	 * only reads.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function export(?string $from = null, ?string $to = null): Response {
		return $this->handleResponse(function () use ($from, $to) {
			$csv = $this->reports->exportCsv($this->uid(), $from, $to);
			return new DataDownloadResponse($csv['content'], $csv['name'], 'text/csv');
		});
	}
}
