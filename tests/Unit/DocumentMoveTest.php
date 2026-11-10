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
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\TextExtractionService;
use OCA\Recruiting\Service\VirusScanService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DocumentMoveTest extends TestCase {
	private function document(int $id, string $key): Document {
		$document = new Document();
		$document->setId($id);
		$document->setCandidateId(2);
		$document->setFileKey($key);
		$document->setName('cv.pdf');
		return $document;
	}

	public function testDocumentsMoveWithTheirFiles(): void {
		$cv = $this->document(1, 'key-cv');
		$lost = $this->document(2, 'key-lost');

		$sourceFile = $this->createMock(ISimpleFile::class);
		$sourceFile->method('getContent')->willReturn('%PDF');
		$sourceFile->expects($this->once())->method('delete');
		$sourceFolder = $this->createMock(ISimpleFolder::class);
		$sourceFolder->method('getFile')->willReturnCallback(static fn (string $key) => $key === 'key-cv'
			? $sourceFile
			: throw new NotFoundException());
		$targetFolder = $this->createMock(ISimpleFolder::class);
		$targetFolder->expects($this->once())->method('newFile')->with('key-cv', '%PDF');

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(static fn (string $name) => match ($name) {
			'candidate-2' => $sourceFolder,
			default => throw new NotFoundException(),
		});
		$appData->expects($this->once())->method('newFolder')->with('candidate-1')->willReturn($targetFolder);

		$mapper = $this->createMock(DocumentMapper::class);
		$mapper->method('findForCandidate')->with(2)->willReturn([$cv, $lost]);
		$mapper->expects($this->once())->method('update')->with($cv);

		$service = new DocumentService(
			$mapper,
			$appData,
			$this->createMock(VirusScanService::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
			$this->createMock(TextExtractionService::class),
			$this->createMock(LoggerInterface::class),
		);
		$target = new Candidate();
		$target->setId(1);

		$this->assertSame(1, $service->moveAll(2, $target));
		$this->assertSame(1, $cv->getCandidateId());
		$this->assertSame(2, $lost->getCandidateId());
	}
}
