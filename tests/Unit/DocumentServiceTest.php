<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\Document;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\TextExtractionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
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
	private DocumentService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(DocumentMapper::class);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->extraction = $this->createMock(TextExtractionService::class);
		$this->extraction->method('supports')->willReturnCallback(
			static fn (string $mime) => in_array($mime, TextExtractionService::EXTRACTABLE_MIMES, true),
		);
		$this->folder = $this->createMock(ISimpleFolder::class);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('k', 32));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-10T10:00:00Z'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);

		$this->service = new DocumentService($this->mapper, $appData, $random, $time, $l10n, $this->extraction, new NullLogger());
	}

	private function candidate(): Candidate {
		$candidate = new Candidate();
		$candidate->setId(9);
		return $candidate;
	}

	public function testExtractedTextIsStoredWithTheDocument(): void {
		$this->extraction->expects($this->once())->method('extract')
			->with('application/pdf', '%PDF-data')->willReturn('Kubernetes engineer');
		$document = $this->service->add($this->candidate(), 'cv.pdf', 'application/pdf; charset=binary', '%PDF-data', null);
		$this->assertSame('Kubernetes engineer', $document->getTextContent());
		$this->assertArrayNotHasKey('textContent', $document->jsonSerialize());
	}

	public function testAFailingExtractionNeverBlocksTheUpload(): void {
		$this->extraction->method('extract')->willThrowException(new \RuntimeException('boom'));
		$this->mapper->expects($this->once())->method('insert');
		$document = $this->service->add($this->candidate(), 'cv.docx', TextExtractionService::MIME_DOCX, 'PK...', null);
		$this->assertNull($document->getTextContent());
	}

	public function testImagesAreNotExtracted(): void {
		$this->extraction->expects($this->never())->method('extract');
		$this->assertNull($this->service->add($this->candidate(), 'photo.png', 'image/png', "\x89PNG", null)->getTextContent());
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
		$withText = new Document();
		$withText->setId(4);
		$withText->setCandidateId(9);
		$withText->setMime('application/pdf');
		$withText->setFileKey('a');
		$noText = new Document();
		$noText->setId(7);
		$noText->setCandidateId(9);
		$noText->setMime('application/pdf');
		$noText->setFileKey('b');
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
}
