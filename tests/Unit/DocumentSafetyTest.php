<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\TextExtractionService;
use OCA\Recruiting\Service\VirusScanService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IAppData;
use OCP\Files\InvalidContentException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Uploaded CVs come from anonymous senders: they are virus-scanned before
 * they are stored (when files_antivirus is installed), and office formats
 * are downloaded instead of opened by the browser.
 */
class DocumentSafetyTest extends TestCase {
	/**
	 * A scanner double shaped like files_antivirus' IScanner/Status.
	 */
	private function fakeScanner(int $status, string $details = '', ?\Throwable $failure = null): object {
		return new class($status, $details, $failure) {
			public ?string $scanned = null;

			public function __construct(
				private int $status,
				private string $details,
				private ?\Throwable $failure,
			) {
			}

			public function scanString(string $data): object {
				if ($this->failure !== null) {
					throw $this->failure;
				}
				$this->scanned = $data;
				return new class($this->status, $this->details) {
					public function __construct(
						private int $status,
						private string $details,
					) {
					}

					public function getNumericStatus(): int {
						return $this->status;
					}

					public function getDetails(): string {
						return $this->details;
					}
				};
			}
		};
	}

	private function scanService(?object $scanner, ?LoggerInterface $logger = null): VirusScanService {
		return new class($scanner, $this->createMock(IAppManager::class), $logger ?? new NullLogger()) extends VirusScanService {
			public function __construct(
				private ?object $fake,
				IAppManager $appManager,
				LoggerInterface $logger,
			) {
				parent::__construct($appManager, $logger);
			}

			protected function scanner(int $size): ?object {
				return $this->fake;
			}
		};
	}

	public function testNothingIsScannedWithoutFilesAntivirus(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->with('files_antivirus')->willReturn(false);
		$this->assertNull((new VirusScanService($appManager, new NullLogger()))->findThreat('content'));
	}

	public function testCleanContentHasNoThreat(): void {
		$scanner = $this->fakeScanner(0);
		$this->assertNull($this->scanService($scanner)->findThreat('%PDF-1.7'));
		$this->assertSame('%PDF-1.7', $scanner->scanned);
	}

	public function testInfectedContentReportsTheThreat(): void {
		$this->assertSame('Eicar-Signature', $this->scanService($this->fakeScanner(1, 'Eicar-Signature'))->findThreat('X5O!P%@AP'));
		$this->assertSame('unknown threat', $this->scanService($this->fakeScanner(1))->findThreat('X5O!P%@AP'));
	}

	/**
	 * Unreachable scanners and unscannable content do not block an
	 * application; that is logged.
	 */
	public function testScannerProblemsStoreAndLog(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(3))->method('warning');
		$this->assertNull($this->scanService($this->fakeScanner(2), $logger)->findThreat('zip bomb'));
		$this->assertNull($this->scanService($this->fakeScanner(-1), $logger)->findThreat('unchecked'));
		$this->assertNull($this->scanService($this->fakeScanner(0, '', new \RuntimeException('clamd unreachable')), $logger)->findThreat('x'));
	}

	private function documentService(VirusScanService $scan, ISimpleFolder $folder, DocumentMapper $mapper): DocumentService {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $params = []) => vsprintf($text, is_array($params) ? $params : [$params]),
		);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('k', 32));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());
		return new DocumentService($mapper, $appData, $scan, $random, $time, $l10n, $this->createMock(TextExtractionService::class), new NullLogger(), $this->createMock(IJobList::class));
	}

	private function candidate(): Candidate {
		$candidate = new Candidate();
		$candidate->setId(5);
		return $candidate;
	}

	public function testInfectedUploadIsRejectedBeforeAnythingIsStored(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->never())->method('newFile');
		$mapper = $this->createMock(DocumentMapper::class);
		$mapper->expects($this->never())->method('insert');
		$service = $this->documentService($this->scanService($this->fakeScanner(1, 'Eicar-Signature')), $folder, $mapper);

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('The file "cv.pdf" was rejected by the virus scanner');
		$service->add($this->candidate(), 'cv.pdf', 'application/pdf', 'X5O!P%@AP', null);
	}

	public function testCleanUploadIsStored(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())->method('newFile')->with(str_repeat('k', 32), '%PDF-1.7')
			->willReturn($this->createMock(ISimpleFile::class));
		$mapper = $this->createMock(DocumentMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$service = $this->documentService($this->scanService($this->fakeScanner(0)), $folder, $mapper);

		$document = $service->add($this->candidate(), 'cv.pdf', 'application/pdf', '%PDF-1.7', null);
		$this->assertSame('cv.pdf', $document->getName());
	}

	/**
	 * When the files_antivirus storage wrapper is active for the request it
	 * blocks the write itself; that becomes the same validation error.
	 */
	public function testStorageLevelBlockBecomesAValidationError(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('newFile')->willThrowException(new InvalidContentException('Virus Eicar-Signature is detected in the file.'));
		$mapper = $this->createMock(DocumentMapper::class);
		$mapper->expects($this->never())->method('insert');
		$service = $this->documentService($this->scanService(null), $folder, $mapper);

		$this->expectException(ValidationException::class);
		$service->add($this->candidate(), 'cv.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'PK..', null);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function dispositions(): array {
		return [
			'pdf' => ['application/pdf', 'inline'],
			'png' => ['image/png', 'inline'],
			'jpeg' => ['image/jpeg', 'inline'],
			'webp' => ['image/webp', 'inline'],
			'text' => ['text/plain', 'inline'],
			'markdown' => ['text/markdown', 'inline'],
			'doc' => ['application/msword', 'attachment'],
			'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'attachment'],
			'odt' => ['application/vnd.oasis.opendocument.text', 'attachment'],
			'unknown' => ['application/octet-stream', 'attachment'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('dispositions')]
	public function testDispositionPerType(string $mime, string $expected): void {
		$this->assertSame($expected, DocumentService::dispositionFor($mime));
	}
}
