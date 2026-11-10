<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Service\TextExtractionService;
use OCP\IBinaryFinder;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TextExtractionServiceTest extends TestCase {
	/** @var string[] */
	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			@unlink($file);
		}
	}

	private function service(string|false $pdftotext = false): TextExtractionService {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = (string)tempnam(sys_get_temp_dir(), 'rcr-test');
			$this->tempFiles[] = $path;
			return $path;
		});
		$finder = $this->createMock(IBinaryFinder::class);
		$finder->method('findBinaryPath')->willReturn($pdftotext);
		return new TextExtractionService($temp, $finder, new NullLogger());
	}

	/**
	 * @param array<string, string> $entries
	 */
	private function zip(array $entries): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'rcr-zip');
		$this->tempFiles[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		return (string)file_get_contents($path);
	}

	private function docx(string $body): string {
		return $this->zip([
			'[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
			'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
				. $body
				. '</w:body></w:document>',
		]);
	}

	/**
	 * A structurally valid single-page PDF with one content stream and the
	 * standard Helvetica font, correct xref offsets included.
	 */
	private function pdf(string $content, bool $compress = true): string {
		$stream = $compress ? (string)gzcompress($content) : $content;
		$objects = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			'<< /Length ' . strlen($stream) . ($compress ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . $stream . "\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		];
		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $index => $object) {
			$offsets[] = strlen($pdf);
			$pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
		}
		$xref = strlen($pdf);
		$pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}
		$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}

	private const CV_CONTENT = "BT /F1 12 Tf 72 720 Td (Ada Lovelace, senior platform engineer) Tj\n"
		. "0 -14 Td [(Kuber) -20 (netes, Terraform) -400 (and Go)] TJ\n"
		. "T* <4765726D616E204331> Tj\n"
		. "(Caf\\351 owner and \\(former\\) mathematician) ' ET";

	public function testPlainTextAndMarkdownPassThrough(): void {
		$service = $this->service();
		$this->assertSame("Line one\n\nLine two", $service->extract('text/plain', "\xEF\xBB\xBFLine one\r\n\r\n\r\n\r\nLine two  "));
		$this->assertSame('# Skills', $service->extract('text/markdown', '# Skills'));
	}

	public function testLegacyEncodedTextIsConverted(): void {
		$this->assertSame('Müller', $this->service()->extract('text/plain', "M\xFCller"));
	}

	public function testEmptyOrUnsupportedYieldsNull(): void {
		$service = $this->service();
		$this->assertNull($service->extract('text/plain', "  \n\t "));
		$this->assertNull($service->extract('image/png', "\x89PNG"));
		$this->assertFalse($service->supports('application/msword'));
		$this->assertTrue($service->supports(TextExtractionService::MIME_DOCX));
	}

	public function testDocxParagraphsTabsAndBreaks(): void {
		$docx = $this->docx(
			'<w:p><w:r><w:t>Ada Lovelace</w:t></w:r></w:p>'
			. '<w:p><w:r><w:t xml:space="preserve">Skills: </w:t></w:r><w:r><w:t>Kubernetes</w:t><w:tab/><w:t>German C1</w:t></w:r></w:p>'
			. '<w:p><w:r><w:t>Line</w:t><w:br/><w:t>break</w:t></w:r><w:r><w:delText>deleted words</w:delText></w:r></w:p>'
			. '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>In a table</w:t></w:r></w:p></w:tc></w:tr></w:tbl>',
		);
		$this->assertSame(
			"Ada Lovelace\nSkills: Kubernetes German C1\nLine\nbreak\nIn a table",
			$this->service()->extract(TextExtractionService::MIME_DOCX, $docx),
		);
	}

	public function testOdtText(): void {
		$odt = $this->zip([
			'mimetype' => 'application/vnd.oasis.opendocument.text',
			'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
				. '<office:automatic-styles><style>not text</style></office:automatic-styles>'
				. '<office:body><office:text>'
				. '<text:h>Curriculum Vitae</text:h>'
				. '<text:p>Grace<text:s text:c="3"/>Hopper<text:tab/>COBOL</text:p>'
				. '<text:p>Navy<office:annotation><text:p>internal remark</text:p></office:annotation> officer<text:line-break/>Compilers</text:p>'
				. '</office:text></office:body></office:document-content>',
		]);
		$this->assertSame(
			"Curriculum Vitae\nGrace Hopper COBOL\nNavy officer\nCompilers",
			$this->service()->extract(TextExtractionService::MIME_ODT, $odt),
		);
	}

	public function testXmlWithADoctypeIsRefused(): void {
		$bomb = $this->zip([
			'word/document.xml' => '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;">]>'
				. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&lol2;</w:t></w:r></w:p></w:body></w:document>',
		]);
		$this->assertNull($this->service()->extract(TextExtractionService::MIME_DOCX, $bomb));
	}

	public function testZipBombIsRejected(): void {
		// Compresses to a few KB, inflates past the cap
		$huge = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'
			. str_repeat('A', TextExtractionService::MAX_XML_BYTES + 1024)
			. '</w:t></w:r></w:p></w:body></w:document>';
		$docx = $this->zip(['word/document.xml' => $huge]);
		unset($huge);
		$this->assertLessThan(1024 * 1024, strlen($docx));
		$this->assertNull($this->service()->extract(TextExtractionService::MIME_DOCX, $docx));
	}

	public function testBrokenArchivesYieldNull(): void {
		$service = $this->service();
		$this->assertNull($service->extract(TextExtractionService::MIME_DOCX, 'not a zip at all'));
		$this->assertNull($service->extract(TextExtractionService::MIME_DOCX, $this->zip(['other.xml' => '<a/>'])));
		$this->assertNull($service->extract(TextExtractionService::MIME_ODT, $this->zip(['content.xml' => '<unclosed'])));
	}

	public function testBuiltinPdfReaderHandlesTheCommonOperators(): void {
		$text = $this->service()->extract(TextExtractionService::MIME_PDF, $this->pdf(self::CV_CONTENT));
		$this->assertNotNull($text);
		$this->assertStringContainsString('Ada Lovelace, senior platform engineer', $text);
		$this->assertStringContainsString('Kubernetes, Terraform and Go', $text);
		$this->assertStringContainsString('German C1', $text);
		$this->assertStringContainsString('Café owner and (former) mathematician', $text);
	}

	public function testUncompressedContentStreamsAreRead(): void {
		$text = $this->service()->extract(TextExtractionService::MIME_PDF, $this->pdf(self::CV_CONTENT, compress: false));
		$this->assertStringContainsString('Kubernetes', (string)$text);
	}

	public function testGarbagePdfTextIsDiscarded(): void {
		// Glyph ids of an embedded CID font: two bytes per glyph, no encoding
		mt_srand(42);
		$glyphs = '';
		for ($i = 0; $i < 400; $i++) {
			$glyphs .= chr(0) . chr(mt_rand(1, 255));
		}
		$content = 'BT /F1 12 Tf <' . bin2hex($glyphs) . '> Tj ET';
		$this->assertNull($this->service()->extract(TextExtractionService::MIME_PDF, $this->pdf($content)));

		$random = '';
		for ($i = 0; $i < 600; $i++) {
			$random .= chr(mt_rand(0, 255));
		}
		$content = 'BT <' . bin2hex($random) . '> Tj ET';
		$this->assertNull($this->service()->extract(TextExtractionService::MIME_PDF, $this->pdf($content)));
	}

	public function testNonPdfBytesAreRejected(): void {
		$this->assertNull($this->service()->extract(TextExtractionService::MIME_PDF, 'BT (Hello world) Tj ET'));
	}

	public function testFailingPdftotextFallsBackToTheBuiltinReader(): void {
		if (!is_executable('/usr/bin/false')) {
			$this->markTestSkipped('/usr/bin/false is not available');
		}
		$text = $this->service('/usr/bin/false')->extract(TextExtractionService::MIME_PDF, $this->pdf(self::CV_CONTENT));
		$this->assertStringContainsString('Kubernetes', (string)$text);
	}

	public function testPdftotextIsUsedWhenInstalled(): void {
		$binary = null;
		foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext'] as $candidate) {
			if (is_executable($candidate)) {
				$binary = $candidate;
				break;
			}
		}
		if ($binary === null) {
			$this->markTestSkipped('pdftotext is not installed');
		}
		$text = $this->service($binary)->extract(TextExtractionService::MIME_PDF, $this->pdf(self::CV_CONTENT));
		$this->assertStringContainsString('Ada Lovelace', (string)$text);
		$this->assertStringContainsString('mathematician', (string)$text);
	}

	public function testLooksLikeText(): void {
		$service = $this->service();
		$this->assertTrue($service->looksLikeText('Senior engineer with ten years of Kubernetes and Go experience.'));
		$this->assertFalse($service->looksLikeText('short'));
		$this->assertFalse($service->looksLikeText(str_repeat('#$%&*!?<>', 10)));
		$this->assertFalse($service->looksLikeText(str_repeat('abcdefghij', 10)), 'no word breaks');
	}

	public function testOutputIsCapped(): void {
		$text = $this->service()->extract('text/plain', str_repeat('word ', TextExtractionService::MAX_CHARS));
		$this->assertSame(TextExtractionService::MAX_CHARS, mb_strlen((string)$text));
	}
}
