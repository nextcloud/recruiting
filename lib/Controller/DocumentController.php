<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\TimelineService;
use OCA\Recruiting\Db\TimelineEvent;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\IMimeTypeDetector;
use OCP\IRequest;
use OCP\IUserSession;

class DocumentController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private DocumentService $documents,
		private DocumentMapper $documentMapper,
		private CandidateService $candidates,
		private PermissionService $permissions,
		private TimelineService $timeline,
		private IMimeTypeDetector $mimeDetector,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function upload(int $candidateId): DataResponse {
		return $this->handle(function () use ($candidateId) {
			$candidate = $this->candidates->find($candidateId);
			$this->permissions->assertCanManageCandidate($this->uid(), $candidate);

			$file = $this->request->getUploadedFile('file');
			if ($file === null || ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
				throw new \OCA\Recruiting\Exception\ValidationException('No file uploaded');
			}
			$content = (string)file_get_contents($file['tmp_name']);
			$mime = $this->mimeDetector->detect($file['tmp_name']);
			if ($mime === 'application/octet-stream') {
				$mime = $this->mimeDetector->detectPath((string)$file['name']);
			}

			$document = $this->documents->add($candidate, (string)$file['name'], $mime, $content, $this->uid());
			$this->timeline->record($candidateId, TimelineEvent::TYPE_DOCUMENT_ADDED, $this->uid(), [
				'name' => $document->getName(),
			]);
			return $document->jsonSerialize();
		});
	}

	/**
	 * Streamed inline in a new browser tab, i.e. a plain navigation without a
	 * request token — read-only, so CSRF protection is not required (and would
	 * break the link).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): Response {
		return $this->handleResponse(function () use ($id) {
			$document = $this->documentMapper->find($id);
			$candidate = $this->candidates->find($document->getCandidateId());
			$this->permissions->assertCanViewCandidate($this->uid(), $candidate);
			$file = $this->documents->getFile($document);

			$response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $document->getMime()]);
			// The content is attacker-supplied: never let the browser sniff a
			// different type, and give the document a locked-down CSP of its
			// own instead of inheriting whatever a middleware defaults to
			$response->addHeader('X-Content-Type-Options', 'nosniff');
			$response->setContentSecurityPolicy(new \OCP\AppFramework\Http\ContentSecurityPolicy());
			// RFC 6266: an ASCII fallback plus the real UTF-8 name, so that
			// "Lebenslauf Müller.pdf" does not arrive percent-encoded
			$name = $document->getName();
			$ascii = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\'], '_', $name)) ?: 'document';
			$response->addHeader(
				'Content-Disposition',
				'inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
			);
			return $response;
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$document = $this->documentMapper->find($id);
			$candidate = $this->candidates->find($document->getCandidateId());
			$this->permissions->assertCanManageCandidate($this->uid(), $candidate);
			$this->documents->delete($document);
			return ['deleted' => true];
		});
	}
}
