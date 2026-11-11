<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\BackgroundJob\ExtractTextJob;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\Document;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\TextExtractionService;
use OCA\Recruiting\Service\VirusScanService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\GenericFileException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DocumentServiceTest extends TestCase {
	private DocumentMapper&MockObject $mapper;
	private TextExtractionService&MockObject $extraction;
	private ISimpleFolder&MockObject $folder;
	private IAppData&MockObject $appData;
	private IJobList&MockObject $jobList;
	private DocumentService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(DocumentMapper::class);
		$this->mapper->method('insert')->willReturnCallback(static function (Document $document): Document {
			$document->setId(31);
			return $document;
		});
		$this->extraction = $this->createMock(TextExtractionService::class);
		$this->extraction->method('supports')->willReturnCallback(
			static fn (string $mime) => in_array($mime, TextExtractionService::EXTRACTABLE_MIMES, true),
		);
		$this->folder = $this->createMock(ISimpleFolder::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->appData->method('getFolder')->willReturn($this->folder);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('k', 32));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-10T10:00:00Z'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$this->jobList = $this->createMock(IJobList::class);

		$this->service = new DocumentService($this->mapper, $this->appData, $this->createMock(VirusScanService::class), $random, $time, $l10n, $this->extraction, new NullLogger(), $this->jobList);
	}

	private function candidate(): Candidate {
		$candidate = new Candidate();
		$candidate->setId(9);
		return $candidate;
	}

	private function document(int $id, string $mime = 'application/pdf', ?string $text = null): Document {
		$document = new Document();
		$document->setId($id);
		$document->setCandidateId(9);
		$document->setMime($mime);
		$document->setFileKey('key' . $id);
		$document->setTextContent($text);
		return $document;
	}

	public function testUploadsAreStoredWithoutExtractingAndTheExtractionIsQueued(): void {
		$this->extraction->expects($this->never())->method('extract');
		$this->jobList->expects($this->once())->method('add')
			->with(ExtractTextJob::class, ['documentIds' => [31], 'intakeCandidateId' => null]);

		$document = $this->service->add($this->candidate(), 'cv.pdf', 'application/pdf; charset=binary', '%PDF-data', null);

		$this->assertNull($document->getTextContent());
		$this->assertArrayNotHasKey('textContent', $document->jsonSerialize());
	}

	public function testImagesQueueNoExtraction(): void {
		$this->jobList->expects($this->never())->method('add');
		$this->service->add($this->candidate(), 'photo.png', 'image/png', "\x89PNG", null);
	}

	public function testACallerCanQueueTheExtractionItself(): void {
		$this->jobList->expects($this->never())->method('add');
		$this->service->add($this->candidate(), 'cv.pdf', 'application/pdf', '%PDF', null, false);
	}

	public function testOneJobExtractsTheDocumentsOfAnIntakeAndNamesTheCandidate(): void {
		$this->jobList->expects($this->once())->method('add')
			->with(ExtractTextJob::class, ['documentIds' => [1, 3], 'intakeCandidateId' => 9]);

		$queued = $this->service->queueTextExtraction([
			$this->document(1),
			$this->document(2, 'image/png'),
			$this->document(3, TextExtractionService::MIME_DOCX),
			$this->document(4, 'application/pdf', 'already extracted'),
		], 9);

		$this->assertTrue($queued);
	}

	public function testNothingExtractableQueuesNoJob(): void {
		$this->jobList->expects($this->never())->method('add');
		$this->assertFalse($this->service->queueTextExtraction([$this->document(2, 'image/png')], 9));
		$this->assertFalse($this->service->queueTextExtraction([], 9));
	}

	public function testTheJobStoresTheExtractedText(): void {
		$document = $this->document(7);
		$this->mapper->method('find')->with(7)->willReturn($document);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('%PDF');
		$this->folder->method('getFile')->with('key7')->willReturn($file);
		$this->extraction->method('extract')->with('application/pdf', '%PDF')->willReturn('Kubernetes engineer');
		$this->mapper->expects($this->once())->method('update')->with($document);

		$this->assertTrue($this->service->extractStoredText(7));
		$this->assertSame('Kubernetes engineer', $document->getTextContent());
	}

	public function testAFailingExtractionLeavesTheDocumentWithoutText(): void {
		$this->mapper->method('find')->willReturn($this->document(7, TextExtractionService::MIME_DOCX));
		$this->folder->method('getFile')->willReturn($this->createMock(ISimpleFile::class));
		$this->extraction->method('extract')->willThrowException(new \RuntimeException('boom'));
		$this->mapper->expects($this->never())->method('update');

		$this->assertFalse($this->service->extractStoredText(7));
	}

	public function testDeletedOrAlreadyExtractedDocumentsAreSkipped(): void {
		$this->mapper->method('find')->willReturnCallback(fn (int $id) => $id === 1
			? throw new DoesNotExistException('gone')
			: $this->document(2, 'application/pdf', 'text'));
		$this->extraction->expects($this->never())->method('extract');

		$this->assertFalse($this->service->extractStoredText(1));
		$this->assertFalse($this->service->extractStoredText(2));
	}

	public function testValidationRejectsDisallowedTypesBeforeStoring(): void {
		$this->folder->expects($this->never())->method('newFile');
		$this->expectException(ValidationException::class);
		$this->service->add($this->candidate(), 'evil.html', 'text/html', '<script>', null);
	}

	public function testValidateRejectsEmptyFiles(): void {
		$this->expectException(ValidationException::class);
		$this->service->validate('application/pdf', '');
	}

	public function testBackfillFillsMissingText(): void {
		$withText = $this->document(4);
		$noText = $this->document(7);
		$this->mapper->expects($this->once())->method('findWithoutText')
			->with(TextExtractionService::EXTRACTABLE_MIMES, 0, 50)->willReturn([$withText, $noText]);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('%PDF');
		$this->folder->method('getFile')->willReturn($file);
		$this->extraction->method('extract')->willReturnOnConsecutiveCalls('Some text', null);
		$this->mapper->expects($this->once())->method('update')->with($withText);

		$this->assertSame(['checked' => 2, 'extracted' => 1, 'lastId' => 7], $this->service->backfillText(0, 50));
		$this->assertSame('Some text', $withText->getTextContent());
	}

	public function testDeletingAllDocumentsRemovesTheFilesBeforeTheRows(): void {
		$order = [];
		$this->folder->expects($this->once())->method('delete')->willReturnCallback(function () use (&$order): void {
			$order[] = 'files';
		});
		$this->mapper->method('findForCandidate')->willReturn([$this->document(1)]);
		$this->folder->method('fileExists')->willReturn(false);
		$this->mapper->expects($this->once())->method('deleteForCandidate')->with(9)->willReturnCallback(function () use (&$order): void {
			$order[] = 'rows';
		});

		$this->service->deleteAllFor(9);

		$this->assertSame(['files', 'rows'], $order);
	}

	public function testAFailedFolderDeleteKeepsTheRows(): void {
		$this->folder->method('delete')->willThrowException(new NotPermittedException('read-only'));
		$this->mapper->expects($this->never())->method('deleteForCandidate');

		$this->expectException(NotPermittedException::class);
		$this->service->deleteAllFor(9);
	}

	public function testAFileThatSurvivesTheDeleteKeepsTheRows(): void {
		$this->mapper->method('findForCandidate')->willReturn([$this->document(1)]);
		$this->folder->method('fileExists')->with('key1')->willReturn(true);
		$this->mapper->expects($this->never())->method('deleteForCandidate');

		$this->expectException(GenericFileException::class);
		$this->service->deleteAllFor(9);
	}

	public function testWithoutAFolderOnlyTheRowsGo(): void {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new NotFoundException());
		$service = new DocumentService($this->mapper, $appData, $this->createMock(VirusScanService::class), $this->createMock(ISecureRandom::class), $this->createMock(ITimeFactory::class), $this->createMock(IL10N::class), $this->extraction, new NullLogger(), $this->jobList);
		$this->mapper->expects($this->once())->method('deleteForCandidate')->with(9);

		$service->deleteAllFor(9);
		$this->mapper->expects($this->once())->method('delete');
		$service->delete($this->document(1));
	}

	public function testDeletingADocumentRemovesTheFileFirst(): void {
		$document = $this->document(1);
		$exists = true;
		$this->folder->method('fileExists')->with('key1')->willReturnCallback(function () use (&$exists) {
			return $exists;
		});
		$file = $this->createMock(ISimpleFile::class);
		$file->expects($this->once())->method('delete')->willReturnCallback(function () use (&$exists): void {
			$exists = false;
		});
		$this->folder->method('getFile')->with('key1')->willReturn($file);
		$this->mapper->expects($this->once())->method('delete')->with($document);

		$this->service->delete($document);
	}

	public function testAFailedFileDeleteKeepsTheDocumentRow(): void {
		$this->folder->method('fileExists')->willReturn(true);
		$file = $this->createMock(ISimpleFile::class);
		$this->folder->method('getFile')->willReturn($file);
		$this->mapper->expects($this->never())->method('delete');

		$this->expectException(GenericFileException::class);
		$this->service->delete($this->document(1));
	}
}
