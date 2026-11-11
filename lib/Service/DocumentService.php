<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\BackgroundJob\ExtractTextJob;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\Document;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\GenericFileException;
use OCP\Files\IAppData;
use OCP\Files\InvalidContentException;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Candidate documents live in app data — never in user Files — and are
 * only served through the app with permission checks (spec §3).
 *
 * Text extraction (for AI material and search) runs in a queued background
 * job, never in the request or the mail intake that stores the file.
 */
class DocumentService {
	/**
	 * Types the browser may render in a tab. Everything else (Word, ODT) is
	 * served as a download, so office documents from unknown senders are
	 * never opened by the browser itself.
	 */
	public const INLINE_MIMES = [
		'application/pdf',
		'image/png',
		'image/jpeg',
		'image/webp',
		'text/plain',
		'text/markdown',
	];

	public function __construct(
		private DocumentMapper $documentMapper,
		private IAppData $appData,
		private VirusScanService $virusScan,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
		private TextExtractionService $textExtraction,
		private LoggerInterface $logger,
		private IJobList $jobList,
	) {
	}

	/**
	 * @return 'inline'|'attachment'
	 */
	public static function dispositionFor(string $mime): string {
		return in_array($mime, self::INLINE_MIMES, true) ? 'inline' : 'attachment';
	}

	/**
	 * @return Document[]
	 */
	public function listFor(int $candidateId): array {
		return $this->documentMapper->findForCandidate($candidateId);
	}

	/**
	 * Check a file against the size limit and the type allow-list without
	 * storing it, so callers can validate before creating anything else.
	 *
	 * @return string the normalised mime type
	 * @throws ValidationException
	 */
	public function validate(string $mime, string $content): string {
		if (strlen($content) === 0) {
			throw new ValidationException($this->l10n->t('The file is empty'));
		}
		if (strlen($content) > ConfigService::MAX_DOCUMENT_SIZE) {
			throw new ValidationException($this->l10n->t('The file exceeds the maximum size of %s MB', [
				(string)(ConfigService::MAX_DOCUMENT_SIZE / 1024 / 1024),
			]));
		}
		// The declared type is constrained to a safe allow-list; the download
		// endpoint then serves the file with exactly this Content-Type plus
		// X-Content-Type-Options: nosniff (see DocumentController::show), so a
		// browser cannot be tricked into executing bytes smuggled under an
		// allowed type — even an anonymous public-form upload. (A content sniff
		// was considered, but OCP's detector reports HTML/SVG fragments as
		// text/plain, so it could not add what the serving hardening already
		// guarantees.)
		$mime = strtolower(trim(explode(';', $mime)[0]));
		if (!in_array($mime, ConfigService::ALLOWED_MIMES, true)) {
			throw new ValidationException($this->l10n->t('This file type is not allowed'));
		}
		return $mime;
	}

	/**
	 * Store a document. Its text is extracted later by a queued job; a
	 * caller that queues the extraction itself (to follow it with the AI
	 * intake analysis) passes $queueExtraction = false and calls
	 * {@see queueTextExtraction()}.
	 *
	 * @throws ValidationException
	 */
	public function add(Candidate $candidate, string $name, string $mime, string $content, ?string $uploadedBy, bool $queueExtraction = true): Document {
		$name = self::sanitizeName($name);
		$mime = $this->validate($mime, $content);

		$threat = $this->virusScan->findThreat($content);
		if ($threat !== null) {
			$this->logger->warning('Rejected an infected document for candidate ' . $candidate->getId() . ': ' . $threat);
			throw new ValidationException($this->l10n->t('The file "%s" was rejected by the virus scanner', [$name]));
		}

		$fileKey = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$folder = $this->folderFor($candidate->getId(), create: true);
		try {
			$folder->newFile($fileKey, $content);
		} catch (InvalidContentException $e) {
			// The files_antivirus storage wrapper, when it is active for this
			// request, blocks the write itself
			$this->logger->warning('The storage refused a document for candidate ' . $candidate->getId() . ': ' . $e->getMessage());
			throw new ValidationException($this->l10n->t('The file "%s" was rejected by the virus scanner', [$name]));
		}

		$document = new Document();
		$document->setCandidateId($candidate->getId());
		$document->setName($name);
		$document->setMime($mime);
		$document->setSize(strlen($content));
		$document->setFileKey($fileKey);
		$document->setUploadedBy($uploadedBy);
		$document->setCreatedAt($this->timeFactory->getDateTime());
		$document = $this->documentMapper->insert($document);
		if ($queueExtraction) {
			$this->queueTextExtraction([$document]);
		}
		return $document;
	}

	/**
	 * Queue one background job that extracts the text of these documents
	 * and, with $intakeCandidateId, then schedules the AI intake analysis of
	 * that candidate, so the analysis sees the CV text.
	 *
	 * @param Document[] $documents
	 * @return bool whether a job was queued: false when none of the
	 *              documents has extractable text
	 */
	public function queueTextExtraction(array $documents, ?int $intakeCandidateId = null): bool {
		$ids = [];
		foreach ($documents as $document) {
			if ($document->getTextContent() === null && $this->textExtraction->supports($document->getMime())) {
				$ids[] = $document->getId();
			}
		}
		if ($ids === []) {
			return false;
		}
		$this->jobList->add(ExtractTextJob::class, [
			'documentIds' => $ids,
			'intakeCandidateId' => $intakeCandidateId,
		]);
		return true;
	}

	/**
	 * Extract and store the text of one stored document, unless it has text
	 * already. A document deleted in the meantime is skipped.
	 *
	 * @return bool whether text was stored
	 */
	public function extractStoredText(int $documentId): bool {
		try {
			$document = $this->documentMapper->find($documentId);
		} catch (DoesNotExistException) {
			return false;
		}
		if ($document->getTextContent() !== null) {
			return false;
		}
		return $this->fillText($document);
	}

	/**
	 * Fill text_content for documents stored before text extraction existed
	 * (or whose extraction failed). Works through the table by id.
	 *
	 * @return array{checked: int, extracted: int, lastId: int}
	 */
	public function backfillText(int $afterId, int $limit): array {
		$checked = 0;
		$extracted = 0;
		$lastId = $afterId;
		foreach ($this->documentMapper->findWithoutText(TextExtractionService::EXTRACTABLE_MIMES, $afterId, $limit) as $document) {
			$checked++;
			$lastId = $document->getId();
			if ($this->fillText($document)) {
				$extracted++;
			}
		}
		return ['checked' => $checked, 'extracted' => $extracted, 'lastId' => $lastId];
	}

	private function fillText(Document $document): bool {
		if (!$this->textExtraction->supports($document->getMime())) {
			return false;
		}
		try {
			$content = $this->getFile($document)->getContent();
		} catch (\Exception) {
			return false;
		}
		$text = $this->extractText($document->getMime(), $content);
		if ($text === null) {
			return false;
		}
		$document->setTextContent($text);
		$this->documentMapper->update($document);
		return true;
	}

	/**
	 * Extraction is a convenience: a failure is logged and leaves the
	 * document without text, it never blocks storing the file.
	 */
	private function extractText(string $mime, string $content): ?string {
		try {
			return $this->textExtraction->extract($mime, $content);
		} catch (\Throwable $e) {
			$this->logger->warning('Recruiting: text extraction failed: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function getFile(Document $document): ISimpleFile {
		return $this->folderFor($document->getCandidateId(), create: false)->getFile($document->getFileKey());
	}

	/**
	 * Remove a document: the file first, then the row. Should the file stay
	 * behind, the row is kept and an exception thrown, so a retry still
	 * finds the file.
	 *
	 * @throws GenericFileException|\OCP\Files\NotPermittedException when the file could not be deleted
	 */
	public function delete(Document $document): void {
		try {
			$folder = $this->folderFor($document->getCandidateId(), create: false);
		} catch (NotFoundException) {
			$folder = null;
		}
		if ($folder !== null && $folder->fileExists($document->getFileKey())) {
			$folder->getFile($document->getFileKey())->delete();
			$this->assertDeleted($folder, [$document]);
		}
		$this->documentMapper->delete($document);
	}

	/**
	 * Remove every document of a candidate: the folder with all files
	 * first, then the rows. Should a file stay behind, the rows are kept and
	 * an exception thrown — never a stored CV without a row pointing at it.
	 *
	 * @throws GenericFileException|\OCP\Files\NotPermittedException when the files could not be deleted
	 */
	public function deleteAllFor(int $candidateId): void {
		try {
			$folder = $this->folderFor($candidateId, create: false);
		} catch (NotFoundException) {
			$folder = null;
		}
		if ($folder !== null) {
			$folder->delete();
			$this->assertDeleted($folder, $this->documentMapper->findForCandidate($candidateId));
		}
		$this->documentMapper->deleteForCandidate($candidateId);
	}

	/**
	 * The storage layer does not report every failed delete, so check.
	 *
	 * @param Document[] $documents
	 * @throws GenericFileException
	 */
	private function assertDeleted(ISimpleFolder $folder, array $documents): void {
		foreach ($documents as $document) {
			if ($folder->fileExists($document->getFileKey())) {
				throw new GenericFileException('The stored file of document ' . $document->getId() . ' could not be deleted');
			}
		}
	}

	/**
	 * Move every document of one candidate to another (merging duplicates):
	 * the stored file is copied into the target's folder, then the row is
	 * re-pointed and the original file removed, so a failure part-way
	 * leaves each document intact on one of the two.
	 *
	 * @return int the number of documents moved
	 */
	public function moveAll(int $fromCandidateId, Candidate $target): int {
		$moved = 0;
		foreach ($this->documentMapper->findForCandidate($fromCandidateId) as $document) {
			try {
				$source = $this->getFile($document);
			} catch (NotFoundException) {
				// file already gone — the row goes with the source candidate
				continue;
			}
			$this->folderFor($target->getId(), create: true)->newFile($document->getFileKey(), $source->getContent());
			$document->setCandidateId($target->getId());
			$this->documentMapper->update($document);
			$source->delete();
			$moved++;
		}
		return $moved;
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

	/**
	 * The name a document is stored under.
	 */
	public static function sanitizeName(string $name): string {
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
