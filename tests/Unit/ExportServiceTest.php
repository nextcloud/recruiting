<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\Document;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Db\Vote;
use OCA\Recruiting\Db\VoteMapper;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\ExportService;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

class ExportServiceTest extends TestCase {
	private DocumentService $documents;
	private VoteMapper $voteMapper;
	private ExportService $service;
	private array $tempFiles = [];

	protected function setUp(): void {
		$this->documents = $this->createMock(DocumentService::class);
		$this->voteMapper = $this->createMock(VoteMapper::class);
		$this->voteMapper->method('findForCandidate')->willReturn([]);

		$eventMapper = $this->createMock(TimelineEventMapper::class);
		$eventMapper->method('findForCandidate')->willReturn([]);
		$interviewMapper = $this->createMock(InterviewMapper::class);
		$interviewMapper->method('findForCandidate')->willReturn([]);
		$offerMapper = $this->createMock(OfferMapper::class);
		$offerMapper->method('findForCandidate')->willReturn([]);

		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function () {
			$path = tempnam(sys_get_temp_dir(), 'recruiting-test-');
			$this->tempFiles[] = $path;
			return $path;
		});

		$this->service = new ExportService(
			$this->documents,
			$eventMapper,
			$interviewMapper,
			$offerMapper,
			$this->voteMapper,
			$tempManager,
			$this->createMock(IL10N::class),
		);
	}

	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
	}

	private function document(int $id, string $name): Document {
		$document = new Document();
		$document->setId($id);
		$document->setCandidateId(5);
		$document->setName($name);
		return $document;
	}

	private function candidate(): Candidate {
		$candidate = new Candidate();
		$candidate->setId(5);
		$candidate->setDisplayName('Jane Doe');
		return $candidate;
	}

	/**
	 * Duplicate document names are common (two "cv.pdf", or several unnamed
	 * mail attachments) and must not silently overwrite each other — a data
	 * access request has to be complete.
	 */
	public function testDuplicateDocumentNamesAreKeptApart(): void {
		$this->documents->method('listFor')->willReturn([
			$this->document(1, 'cv.pdf'),
			$this->document(2, 'cv.pdf'),
			$this->document(3, 'attachment'),
			$this->document(4, 'attachment'),
		]);
		$this->documents->method('getFile')->willReturnCallback(function (Document $document) {
			$file = $this->createMock(ISimpleFile::class);
			$file->method('getContent')->willReturn('content-' . $document->getId());
			return $file;
		});

		$result = $this->service->buildZip($this->candidate());
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($result['path']) === true);

		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}
		$this->assertContains('documents/cv.pdf', $names);
		$this->assertContains('documents/cv-2.pdf', $names);
		$this->assertContains('documents/attachment', $names);
		$this->assertContains('documents/attachment-2', $names);
		// all four documents survived, none overwrote another
		$this->assertSame('content-2', $zip->getFromName('documents/cv-2.pdf'));
		$zip->close();
	}

	public function testExportContainsProfileButNoReviewerIdentities(): void {
		$this->documents->method('listFor')->willReturn([]);
		$vote = new Vote();
		$vote->setCandidateId(5);
		$vote->setUid('secret-reviewer');
		$vote->setVote(Vote::YES);
		$vote->setComment('internal reasoning');
		$voteMapper = $this->createMock(VoteMapper::class);
		$voteMapper->method('findForCandidate')->willReturn([$vote]);

		$eventMapper = $this->createMock(TimelineEventMapper::class);
		$eventMapper->method('findForCandidate')->willReturn([]);
		$interviewMapper = $this->createMock(InterviewMapper::class);
		$interviewMapper->method('findForCandidate')->willReturn([]);
		$offerMapper = $this->createMock(OfferMapper::class);
		$offerMapper->method('findForCandidate')->willReturn([]);
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function () {
			$path = tempnam(sys_get_temp_dir(), 'recruiting-test-');
			$this->tempFiles[] = $path;
			return $path;
		});

		$service = new ExportService(
			$this->documents,
			$eventMapper,
			$interviewMapper,
			$offerMapper,
			$voteMapper,
			$tempManager,
			$this->createMock(IL10N::class),
		);
		$result = $service->buildZip($this->candidate());

		$zip = new \ZipArchive();
		$zip->open($result['path']);
		$json = $zip->getFromName('candidate.json');
		$zip->close();

		$this->assertStringContainsString('Jane Doe', $json);
		$this->assertStringNotContainsString('secret-reviewer', $json);
		$this->assertStringNotContainsString('internal reasoning', $json);
		$this->assertStringContainsString('"vote": "yes"', $json);
	}

	public function testFileNameIsSanitized(): void {
		$this->documents->method('listFor')->willReturn([]);
		$candidate = $this->candidate();
		$candidate->setDisplayName('../../etc/passwd');
		$result = $this->service->buildZip($candidate);
		$this->assertStringNotContainsString('/', $result['name']);
		$this->assertStringEndsWith('.zip', $result['name']);
	}
}
