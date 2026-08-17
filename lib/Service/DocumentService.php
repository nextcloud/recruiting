<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\Document;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use OCP\Security\ISecureRandom;

/**
 * Candidate documents live in app data — never in user Files — and are
 * only served through the app with permission checks (spec §3).
 */
class DocumentService {
	public function __construct(
		private DocumentMapper $documentMapper,
		private IAppData $appData,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return Document[]
	 */
	public function listFor(int $candidateId): array {
		return $this->documentMapper->findForCandidate($candidateId);
	}

	/**
	 * @throws ValidationException
	 */
	public function add(Candidate $candidate, string $name, string $mime, string $content, ?string $uploadedBy): Document {
		$name = $this->sanitizeName($name);
		if (strlen($content) === 0) {
			throw new ValidationException($this->l10n->t('The file is empty'));
		}
		if (strlen($content) > ConfigService::MAX_DOCUMENT_SIZE) {
			throw new ValidationException($this->l10n->t('The file exceeds the maximum size of %s MB', [
				(string)(ConfigService::MAX_DOCUMENT_SIZE / 1024 / 1024),
			]));
		}
		$mime = strtolower(trim(explode(';', $mime)[0]));
		if (!in_array($mime, ConfigService::ALLOWED_MIMES, true)) {
			throw new ValidationException($this->l10n->t('This file type is not allowed'));
		}

		$fileKey = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$folder = $this->folderFor($candidate->getId(), create: true);
		$folder->newFile($fileKey, $content);

		$document = new Document();
		$document->setCandidateId($candidate->getId());
		$document->setName($name);
		$document->setMime($mime);
		$document->setSize(strlen($content));
		$document->setFileKey($fileKey);
		$document->setUploadedBy($uploadedBy);
		$document->setCreatedAt($this->timeFactory->getDateTime());
		return $this->documentMapper->insert($document);
	}

	public function getFile(Document $document): ISimpleFile {
		return $this->folderFor($document->getCandidateId(), create: false)->getFile($document->getFileKey());
	}

	public function delete(Document $document): void {
		try {
			$this->getFile($document)->delete();
		} catch (NotFoundException) {
			// already gone — still remove the row
		}
		$this->documentMapper->delete($document);
	}

	/**
	 * Remove every document (rows and files) of a candidate.
	 */
	public function deleteAllFor(int $candidateId): void {
		$this->documentMapper->deleteForCandidate($candidateId);
		try {
			$this->folderFor($candidateId, create: false)->delete();
		} catch (NotFoundException) {
			// nothing stored
		}
	}

	private function folderFor(int $candidateId, bool $create): ISimpleFolder {
		$name = 'candidate-' . $candidateId;
		try {
			return $this->appData->getFolder($name);
		} catch (NotFoundException $e) {
			if (!$create) {
				throw $e;
			}
			return $this->appData->newFolder($name);
		}
	}

	private function sanitizeName(string $name): string {
		$name = trim(str_replace(['/', '\\', "\0"], '_', $name));
		if ($name === '' || $name === '.' || $name === '..') {
			$name = 'document';
		}
		// Keep the beginning of the name (and its extension), not the tail
		if (mb_strlen($name) > 255) {
			$extension = pathinfo($name, PATHINFO_EXTENSION);
			$suffix = $extension !== '' ? '.' . mb_substr($extension, 0, 20) : '';
			$name = mb_substr($name, 0, 255 - mb_strlen($suffix)) . $suffix;
		}
		return $name;
	}
}
