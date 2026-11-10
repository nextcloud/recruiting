<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCP\IBinaryFinder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Plain text from candidate documents (CVs) for AI material and full-text
 * search, without any library dependency.
 *
 * Every input is attacker-supplied: archives are read with an uncompressed
 * size cap, XML is parsed without DTDs or network access, `pdftotext` runs
 * without a shell and with a timeout, and the built-in PDF reader discards
 * its result when it does not look like human text. Returns null whenever
 * nothing usable comes out — never garbage.
 */
class TextExtractionService {
	/** Stored text is capped at this many characters */
	public const MAX_CHARS = 100_000;

	public const MIME_PDF = 'application/pdf';
	public const MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
	public const MIME_ODT = 'application/vnd.oasis.opendocument.text';

	public const EXTRACTABLE_MIMES = [
		self::MIME_PDF,
		self::MIME_DOCX,
		self::MIME_ODT,
		'text/plain',
		'text/markdown',
	];

	/** Uncompressed size cap for the XML part read from a DOCX/ODT archive */
	public const MAX_XML_BYTES = 16 * 1024 * 1024;
	/** Total inflated stream data the built-in PDF reader may produce */
	private const MAX_PDF_INFLATED_BYTES = 32 * 1024 * 1024;
	private const MAX_PDF_STREAMS = 5000;
	private const MAX_TOOL_OUTPUT_BYTES = 4 * 1024 * 1024;
	private const PDFTOTEXT_TIMEOUT_SECONDS = 20;

	private const NS_WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	private const NS_ODF_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
	private const NS_ODF_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

	public function __construct(
		private ITempManager $tempManager,
		private IBinaryFinder $binaryFinder,
		private LoggerInterface $logger,
	) {
	}

	public function supports(string $mime): bool {
		return in_array($mime, self::EXTRACTABLE_MIMES, true);
	}

	/**
	 * The document's text, normalised and capped at MAX_CHARS, or null when
	 * the type is not supported or nothing readable could be extracted.
	 */
	public function extract(string $mime, string $content): ?string {
		$text = match ($mime) {
			'text/plain', 'text/markdown' => $this->fromPlainText($content),
			self::MIME_DOCX => $this->fromArchive($content, 'word/document.xml', fn (\DOMDocument $doc) => $this->docxText($doc)),
			self::MIME_ODT => $this->fromArchive($content, 'content.xml', fn (\DOMDocument $doc) => $this->odtText($doc)),
			self::MIME_PDF => $this->fromPdf($content),
			default => null,
		};
		return $text === null ? null : $this->normalize($text);
	}

	/**
	 * Heuristic gate for PDF output: real text is mostly letters, digits and
	 * whitespace, has word breaks and a handful of actual words. Glyph-id
	 * soup from embedded CID fonts or encrypted streams fails all three.
	 */
	public function looksLikeText(string $text): bool {
		$text = trim(mb_scrub($text, 'UTF-8'));
		$total = mb_strlen($text);
		if ($total < 20) {
			return false;
		}
		$letters = (int)preg_match_all('/\p{L}/u', $text);
		$digits = (int)preg_match_all('/\p{N}/u', $text);
		$spaces = (int)preg_match_all('/\s/u', $text);
		if (($letters + $digits + $spaces) / $total < 0.75) {
			return false;
		}
		if ($spaces / $total < 0.05) {
			return false;
		}
		return (int)preg_match_all('/\p{L}{2,}/u', $text) >= 5;
	}

	private function fromPlainText(string $content): string {
		if (str_starts_with($content, "\xEF\xBB\xBF")) {
			$content = substr($content, 3);
		}
		if (!mb_check_encoding($content, 'UTF-8')) {
			$content = (string)mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
		}
		return $content;
	}

	/**
	 * Read one XML part out of a zip container (DOCX, ODT).
	 *
	 * @param \Closure(\DOMDocument): string $toText
	 */
	private function fromArchive(string $content, string $entry, \Closure $toText): ?string {
		$xml = $this->readZipEntry($content, $entry);
		if ($xml === null) {
			return null;
		}
		$doc = $this->parseXml($xml);
		return $doc === null ? null : $toText($doc);
	}

	/**
	 * The uncompressed bytes of one archive entry. The declared size is
	 * checked first and the actual stream is read with the same cap, so a
	 * forged size header cannot sneak a zip bomb past the check.
	 */
	private function readZipEntry(string $content, string $entry): ?string {
		$path = $this->tempManager->getTemporaryFile('.zip');
		if ($path === false) {
			return null;
		}
		try {
			if (file_put_contents($path, $content) === false) {
				return null;
			}
			$zip = new \ZipArchive();
			if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
				return null;
			}
			try {
				$stat = $zip->statName($entry);
				if ($stat === false) {
					return null;
				}
				if ($stat['size'] > self::MAX_XML_BYTES) {
					$this->logger->info('Recruiting: document text skipped, archive entry exceeds the size cap');
					return null;
				}
				$stream = $zip->getStream($entry);
				if ($stream === false) {
					return null;
				}
				$data = '';
				while (!feof($stream)) {
					$chunk = fread($stream, 65536);
					if ($chunk === false) {
						break;
					}
					$data .= $chunk;
					if (strlen($data) > self::MAX_XML_BYTES) {
						fclose($stream);
						$this->logger->info('Recruiting: document text skipped, archive entry exceeds the size cap');
						return null;
					}
				}
				fclose($stream);
				return $data;
			} finally {
				$zip->close();
			}
		} finally {
			@unlink($path);
		}
	}

	/**
	 * Parse without DTD support (a DOCTYPE is refused outright, which rules
	 * out entity expansion bombs and external entities) and without network
	 * access. Entities are never substituted (no LIBXML_NOENT).
	 */
	private function parseXml(string $xml): ?\DOMDocument {
		if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
			return null;
		}
		$previous = libxml_use_internal_errors(true);
		try {
			$doc = new \DOMDocument();
			$loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
			libxml_clear_errors();
			return $loaded ? $doc : null;
		} finally {
			libxml_use_internal_errors($previous);
		}
	}

	/**
	 * WordprocessingML: text lives in w:t runs; w:tab and w:br are explicit,
	 * every w:p ends a line. Deleted text (w:delText) and field codes are not
	 * w:t and therefore skipped.
	 */
	private function docxText(\DOMDocument $doc): string {
		$root = $doc->documentElement;
		if ($root === null) {
			return '';
		}
		return $this->walk($root, function (\DOMNode $node, bool $leaving): ?string {
			if ($node instanceof \DOMText) {
				$parent = $node->parentNode;
				return ($parent !== null && $parent->namespaceURI === self::NS_WORD && $parent->localName === 't') ? $node->data : null;
			}
			if ($node->namespaceURI !== self::NS_WORD) {
				return null;
			}
			return match (true) {
				$leaving && $node->localName === 'p' => "\n",
				!$leaving && $node->localName === 'tab' => "\t",
				!$leaving && ($node->localName === 'br' || $node->localName === 'cr') => "\n",
				default => null,
			};
		});
	}

	/**
	 * OpenDocument text: everything inside office:body except annotations;
	 * text:s carries a space count, paragraphs and headings end lines.
	 */
	private function odtText(\DOMDocument $doc): string {
		$body = $doc->getElementsByTagNameNS(self::NS_ODF_OFFICE, 'body')->item(0);
		if ($body === null) {
			return '';
		}
		return $this->walk($body, function (\DOMNode $node, bool $leaving): ?string {
			if ($node instanceof \DOMText) {
				return $node->data;
			}
			if ($node->namespaceURI !== self::NS_ODF_TEXT) {
				return null;
			}
			if ($leaving) {
				return ($node->localName === 'p' || $node->localName === 'h') ? "\n" : null;
			}
			if ($node->localName === 's' && $node instanceof \DOMElement) {
				$count = (int)($node->getAttributeNS(self::NS_ODF_TEXT, 'c') ?: 1);
				return str_repeat(' ', max(1, min(100, $count)));
			}
			return match ($node->localName) {
				'tab' => "\t",
				'line-break' => "\n",
				default => null,
			};
		}, skip: static fn (\DOMNode $node): bool => $node->namespaceURI === self::NS_ODF_OFFICE && $node->localName === 'annotation');
	}

	/**
	 * Iterative document-order walk (no recursion, so nesting depth cannot
	 * exhaust the stack). $emit is called on entering and leaving every
	 * node and returns the text to append, if any.
	 *
	 * @param \Closure(\DOMNode, bool): ?string $emit
	 * @param ?\Closure(\DOMNode): bool $skip subtrees to leave out entirely
	 */
	private function walk(\DOMNode $root, \Closure $emit, ?\Closure $skip = null): string {
		$out = '';
		/** @var list<array{0: \DOMNode, 1: bool}> $stack */
		$stack = [[$root, false]];
		while ($stack !== []) {
			[$node, $leaving] = array_pop($stack);
			if (!$leaving && $skip !== null && $skip($node)) {
				continue;
			}
			$out .= $emit($node, $leaving) ?? '';
			if ($leaving || !$node->hasChildNodes()) {
				continue;
			}
			$stack[] = [$node, true];
			for ($child = $node->lastChild; $child !== null; $child = $child->previousSibling) {
				$stack[] = [$child, false];
			}
			if (strlen($out) > self::MAX_CHARS * 4) {
				break;
			}
		}
		return $out;
	}

	private function fromPdf(string $content): ?string {
		$header = strpos(substr($content, 0, 1024), '%PDF');
		if ($header === false) {
			return null;
		}
		$text = $this->runPdftotext($content) ?? $this->builtinPdfText($content);
		if ($text === null || !$this->looksLikeText($text)) {
			return null;
		}
		return $text;
	}

	/**
	 * Poppler's pdftotext, when installed: an argument vector (no shell),
	 * a hard timeout and an output cap. Null when unavailable or failing.
	 */
	private function runPdftotext(string $content): ?string {
		$binary = $this->binaryFinder->findBinaryPath('pdftotext');
		if (!is_string($binary) || $binary === '') {
			return null;
		}
		$input = $this->tempManager->getTemporaryFile('.pdf');
		if ($input === false) {
			return null;
		}
		try {
			if (file_put_contents($input, $content) === false) {
				return null;
			}
			$pipes = [];
			$process = proc_open(
				[$binary, '-q', '-enc', 'UTF-8', '-nopgbrk', $input, '-'],
				[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
				$pipes,
			);
			if (!is_resource($process)) {
				return null;
			}
			fclose($pipes[0]);
			stream_set_blocking($pipes[1], false);
			stream_set_blocking($pipes[2], false);

			$output = '';
			$timedOut = false;
			$deadline = microtime(true) + (float)self::PDFTOTEXT_TIMEOUT_SECONDS;
			$open = [1 => $pipes[1], 2 => $pipes[2]];
			while ($open !== []) {
				$remaining = $deadline - microtime(true);
				if ($remaining <= 0) {
					$timedOut = true;
					break;
				}
				$read = array_values($open);
				$write = null;
				$except = null;
				if (stream_select($read, $write, $except, (int)$remaining, (int)(fmod($remaining, 1.0) * 1_000_000.0)) === false) {
					break;
				}
				foreach ($read as $stream) {
					$chunk = fread($stream, 65536);
					if ($stream === $pipes[1] && is_string($chunk)) {
						$output .= $chunk;
					}
					$key = array_search($stream, $open, true);
					if (feof($stream) && $key !== false) {
						unset($open[$key]);
					}
				}
				if (strlen($output) > self::MAX_TOOL_OUTPUT_BYTES) {
					break;
				}
			}
			if ($open !== []) {
				proc_terminate($process, 9);
			}
			fclose($pipes[1]);
			fclose($pipes[2]);
			$status = proc_close($process);
			if ($timedOut) {
				$this->logger->info('Recruiting: pdftotext timed out, document text skipped');
				return null;
			}
			if ($status !== 0 && $output === '') {
				return null;
			}
			return $output;
		} finally {
			@unlink($input);
		}
	}

	/**
	 * Best-effort reader for the common case: FlateDecode (or unfiltered)
	 * content streams drawing literal or hex strings with Tj/TJ/'/". Fonts,
	 * images, metadata and anything with another filter are skipped.
	 */
	private function builtinPdfText(string $pdf): ?string {
		$budget = self::MAX_PDF_INFLATED_BYTES;
		$text = '';
		$offset = 0;
		$streams = 0;
		while ($streams < self::MAX_PDF_STREAMS && ($pos = strpos($pdf, 'stream', $offset)) !== false) {
			$offset = $pos + 6;
			if ($pos >= 3 && substr($pdf, $pos - 3, 3) === 'end') {
				continue;
			}
			$dataStart = $pos + 6;
			if (substr($pdf, $dataStart, 2) === "\r\n") {
				$dataStart += 2;
			} elseif (($pdf[$dataStart] ?? '') === "\n" || ($pdf[$dataStart] ?? '') === "\r") {
				$dataStart++;
			} else {
				continue;
			}
			$end = strpos($pdf, 'endstream', $dataStart);
			if ($end === false) {
				break;
			}
			$offset = $end + 9;
			$streams++;

			$head = substr($pdf, max(0, $pos - 4096), min($pos, 4096));
			$objPos = strrpos($head, 'obj');
			$dict = $objPos !== false ? substr($head, $objPos) : $head;
			if (preg_match('#/Subtype\s*/(Image|Type1C|CIDFontType0C|OpenType|XML)|/Length[123]\b|/Type\s*/(XRef|ObjStm|Metadata|EmbeddedFile)#', $dict)) {
				continue;
			}
			$data = substr($pdf, $dataStart, $end - $dataStart);
			if (preg_match('#/Filter\s*\[?\s*/FlateDecode\s*\]?#', $dict)) {
				if ($budget <= 0) {
					break;
				}
				$decoded = @gzuncompress($data, $budget);
				if ($decoded === false) {
					continue;
				}
				$budget -= strlen($decoded);
				$data = $decoded;
			} elseif (str_contains($dict, '/Filter')) {
				continue;
			}
			if (!str_contains($data, 'BT')) {
				continue;
			}
			$text .= $this->contentStreamText($data) . "\n";
			if (strlen($text) > self::MAX_CHARS * 4) {
				break;
			}
		}
		return trim($text) === '' ? null : $text;
	}

	/**
	 * Interpret the text-showing operators of one content stream. A small
	 * tokenizer: operands are collected until an operator consumes them.
	 */
	private function contentStreamText(string $s): string {
		$out = '';
		$operands = [];
		$array = null;
		$len = strlen($s);
		$i = 0;
		while ($i < $len) {
			$c = $s[$i];
			if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\f" || $c === "\0") {
				$i++;
				continue;
			}
			if ($c === '%') {
				$eol = strcspn($s, "\r\n", $i);
				$i += $eol;
				continue;
			}
			if ($c === '(') {
				[$string, $i] = $this->literalString($s, $i);
				$this->pushOperand($operands, $array, $string);
				continue;
			}
			if ($c === '<') {
				if (($s[$i + 1] ?? '') === '<') {
					$i += 2;
					continue;
				}
				$close = strpos($s, '>', $i);
				if ($close === false) {
					break;
				}
				$hex = (string)preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $close - $i - 1));
				if (strlen($hex) % 2 === 1) {
					$hex .= '0';
				}
				$string = (string)hex2bin($hex);
				$this->pushOperand($operands, $array, $string);
				$i = $close + 1;
				continue;
			}
			if ($c === '>' || $c === '{' || $c === '}') {
				$i++;
				continue;
			}
			if ($c === '[') {
				$array = [];
				$i++;
				continue;
			}
			if ($c === ']') {
				$operands[] = $array ?? [];
				$array = null;
				$i++;
				continue;
			}
			if ($c === '/') {
				$i++;
				$i += strcspn($s, " \t\r\n\f\0()<>[]{}/%", $i);
				continue;
			}
			$tokenLength = strcspn($s, " \t\r\n\f\0()<>[]{}/%", $i);
			$token = substr($s, $i, max(1, $tokenLength));
			$i += max(1, $tokenLength);
			if (is_numeric($token)) {
				$this->pushOperand($operands, $array, (float)$token);
				continue;
			}

			$last = end($operands);
			switch ($token) {
				case 'Tj':
					$out .= is_string($last) ? $this->pdfString($last) : '';
					break;
				case "'":
				case '"':
					$out .= "\n" . (is_string($last) ? $this->pdfString($last) : '');
					break;
				case 'TJ':
					foreach (is_array($last) ? $last : [] as $part) {
						if (is_string($part)) {
							$out .= $this->pdfString($part);
						} elseif ($part < -200) {
							$out .= ' ';
						}
					}
					break;
				case 'Td':
				case 'TD':
					$ty = $last;
					$out .= (is_float($ty) && abs($ty) > 0.01) ? "\n" : ' ';
					break;
				case 'T*':
				case 'Tm':
				case 'ET':
					$out .= "\n";
					break;
				case 'BI':
					// Inline image data is binary up to "EI"
					$stop = strpos($s, 'EI', $i);
					$i = $stop === false ? $len : $stop + 2;
					break;
			}
			$operands = [];
		}
		return $out;
	}

	/**
	 * Operands inside "[ … ]" belong to the array being built (TJ).
	 *
	 * @param list<mixed> $operands
	 * @param ?list<string|float> $array
	 */
	private function pushOperand(array &$operands, ?array &$array, string|float $value): void {
		if ($array !== null) {
			$array[] = $value;
		} else {
			$operands[] = $value;
		}
	}

	/**
	 * @return array{0: string, 1: int} the decoded bytes and the offset after the string
	 */
	private function literalString(string $s, int $i): array {
		$len = strlen($s);
		$depth = 0;
		$out = '';
		for ($i++; $i < $len; $i++) {
			$c = $s[$i];
			if ($c === '\\') {
				$next = $s[$i + 1] ?? '';
				$i++;
				if ($next >= '0' && $next <= '7') {
					$octal = $next;
					while (strlen($octal) < 3 && ($s[$i + 1] ?? '') >= '0' && ($s[$i + 1] ?? '') <= '7') {
						$octal .= $s[++$i];
					}
					$out .= chr(((int)octdec($octal)) & 0xFF);
					continue;
				}
				$out .= match ($next) {
					'n' => "\n",
					'r' => "\r",
					't' => "\t",
					'b' => "\x08",
					'f' => "\f",
					"\r", "\n" => '',
					default => $next,
				};
				if ($next === "\r" && ($s[$i + 1] ?? '') === "\n") {
					$i++;
				}
				continue;
			}
			if ($c === '(') {
				$depth++;
			} elseif ($c === ')') {
				if ($depth === 0) {
					return [$out, $i + 1];
				}
				$depth--;
			}
			$out .= $c;
		}
		return [$out, $len];
	}

	/**
	 * PDF strings are UTF-16BE with a BOM or a single-byte encoding; the
	 * latter is read as Windows-1252, which matches WinAnsiEncoding.
	 */
	private function pdfString(string $bytes): string {
		if (str_starts_with($bytes, "\xFE\xFF")) {
			return (string)mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
		}
		return (string)mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
	}

	private function normalize(string $text): ?string {
		$text = mb_scrub($text, 'UTF-8');
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = (string)preg_replace('/[^\P{C}\n\t]/u', '', $text);
		$text = (string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
		$text = (string)preg_replace('/ *\n */', "\n", $text);
		$text = (string)preg_replace('/\n{3,}/', "\n\n", $text);
		$text = trim($text);
		if ($text === '') {
			return null;
		}
		return mb_substr($text, 0, self::MAX_CHARS);
	}
}
