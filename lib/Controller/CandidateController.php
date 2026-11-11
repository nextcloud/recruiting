<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Service\BulkRejectService;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\ExportService;
use OCA\Recruiting\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

class CandidateController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private CandidateService $candidates,
		private ExportService $export,
		private \OCA\Recruiting\Service\PoolService $pool,
		private PermissionService $permissions,
		private BulkRejectService $bulkReject,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $openingId, ?string $stage = null, int $offset = 0): DataResponse {
		return $this->handle(fn () => $this->candidates->listForOpening($this->uid(), $openingId, $stage, $offset));
	}

	#[NoAdminRequired]
	public function triage(int $offset = 0): DataResponse {
		return $this->handle(fn () => $this->candidates->triage($this->uid(), $offset));
	}

	#[NoAdminRequired]
	public function myReviews(): DataResponse {
		return $this->handle(fn () => $this->candidates->myReviews($this->uid()));
	}

	#[NoAdminRequired]
	public function myInterviews(): DataResponse {
		return $this->handle(fn () => $this->candidates->myInterviews($this->uid()));
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(fn () => $this->candidates->get($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function card(int $id): DataResponse {
		return $this->handle(fn () => $this->candidates->card($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function create(): DataResponse {
		return $this->handle(fn () => $this->candidates->createManual($this->uid(), $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function update(int $id): DataResponse {
		return $this->handle(fn () => $this->candidates->update($this->uid(), $id, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function setStage(int $id, string $stage, ?string $expectedStage = null): DataResponse {
		return $this->handle(fn () => $this->candidates->setStage($this->uid(), $id, $stage, $expectedStage));
	}

	#[NoAdminRequired]
	public function assignOpening(int $id, int $openingId): DataResponse {
		return $this->handle(fn () => $this->candidates->assignToOpening($this->uid(), $id, $openingId));
	}

	#[NoAdminRequired]
	public function reject(int $id, string $reason, ?string $mailSubject = null, ?string $mailBody = null, bool $askPool = false): DataResponse {
		return $this->handle(fn () => $this->candidates->reject($this->uid(), $id, $reason, $mailSubject, $mailBody, $askPool));
	}

	/**
	 * Reject up to BulkRejectService::MAX_PER_REQUEST candidates with one
	 * reason and one mail template, rendered per candidate.
	 *
	 * @param list<int|string> $ids
	 */
	#[NoAdminRequired]
	public function bulkReject(array $ids, string $reason, bool $sendMail = false, string $subject = '', string $body = '', bool $askPool = false): DataResponse {
		return $this->handle(fn () => $this->bulkReject->reject($this->uid(), $ids, $reason, $sendMail, $subject, $body, $askPool));
	}

	/**
	 * Talent pool (recruiters only).
	 */
	#[NoAdminRequired]
	public function pool(int $offset = 0, string $q = ''): DataResponse {
		return $this->handle(fn () => $this->pool->listMembers($this->uid(), $offset, $q));
	}

	#[NoAdminRequired]
	public function addPoolToOpening(int $id, int $openingId): DataResponse {
		return $this->handle(fn () => $this->candidates->addPoolCandidateToOpening($this->uid(), $id, $openingId));
	}

	/**
	 * GDPR data-access export: one ZIP with candidate.json + documents.
	 * Recruiter-only, like deletion (spec §4.8).
	 *
	 * Downloaded through a plain link (no request token), so CSRF protection
	 * is not required — the endpoint only reads.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function export(int $id): Response {
		return $this->handleResponse(function () use ($id) {
			$this->permissions->assertRecruiter($this->uid());
			$candidate = $this->candidates->find($id);
			$zip = $this->export->buildZip($candidate);
			// ITempManager removes the file at the end of the request
			return new DataDownloadResponse((string)file_get_contents($zip['path']), $zip['name'], 'application/zip');
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->candidates->destroy($this->uid(), $id);
			return ['deleted' => true];
		});
	}

	/**
	 * Merge candidate $id (the duplicate, deleted afterwards) into
	 * $targetId; answers with the target's detail.
	 */
	#[NoAdminRequired]
	public function merge(int $id, int $targetId): DataResponse {
		return $this->handle(fn () => $this->candidates->merge($this->uid(), $id, $targetId));
	}
}
