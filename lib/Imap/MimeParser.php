<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Imap;

/**
 * Small, dependency-free MIME parser for the ingestion path. Handles the
 * shapes real application mails come in: nested multiparts, base64 and
 * quoted-printable transfer encodings, RFC 2047 encoded headers, RFC 2231
 * encoded file names and non-UTF-8 charsets.
 */
class MimeParser {
	public const MAX_ATTACHMENTS = 10;
	public const MAX_ATTACHMENT_SIZE = 20 * 1024 * 1024;
	public const MAX_BODY_LENGTH = 100_000;

	private string $textBody = '';
	private string $htmlBody = '';
	/** @var array<int, array{name: string, mime: string, content: string}> */
	private array $attachments = [];

	public function parse(string $raw): ParsedMail {
		$this->textBody = '';
		$this->htmlBody = '';
		$this->attachments = [];

		[$headers, $body] = $this->splitHeadersBody($raw);
		$this->walkPart($headers, $body);

		$text = $this->textBody !== '' ? $this->textBody : $this->htmlToText($this->htmlBody);
		$text = trim(mb_substr($text, 0, self::MAX_BODY_LENGTH));

		[$fromName, $fromEmail] = $this->parseAddress($headers['from'] ?? '');

		$recipients = [];
		foreach (['to', 'cc', 'delivered-to', 'x-original-to', 'envelope-to'] as $header) {
			foreach ($this->extractAddresses($headers[$header] ?? '') as $address) {
				$recipients[] = $address;
			}
		}

		// Message-IDs are attacker-controlled headers: clamp to the column size
		$messageId = mb_substr(trim($headers['message-id'] ?? ''), 0, 250);

		return new ParsedMail(
			$this->decodeHeader($headers['subject'] ?? ''),
			$fromName,
			strtolower($fromEmail),
			array_values(array_unique($recipients)),
			$text,
			$this->attachments,
			$messageId,
		);
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function walkPart(array $headers, string $body): void {
		$contentType = $headers['content-type'] ?? 'text/plain';
		[$mime, $params] = $this->parseContentType($contentType);

		if (str_starts_with($mime, 'multipart/')) {
			$boundary = $params['boundary'] ?? '';
			if ($boundary === '') {
				return;
			}
			foreach ($this->splitMultipart($body, $boundary) as $part) {
				[$partHeaders, $partBody] = $this->splitHeadersBody($part);
				$this->walkPart($partHeaders, $partBody);
			}
			return;
		}

		$disposition = strtolower(trim(explode(';', $headers['content-disposition'] ?? '')[0]));
		$fileName = $this->fileNameFor($headers, $params);
		$decoded = $this->decodeTransferEncoding($body, strtolower(trim($headers['content-transfer-encoding'] ?? '')));

		if ($disposition === 'attachment' || ($fileName !== '' && $disposition !== 'inline' && !str_starts_with($mime, 'text/'))
			|| ($fileName !== '' && $disposition === '' && !str_starts_with($mime, 'text/'))) {
			$this->addAttachment($fileName, $mime, $decoded);
			return;
		}

		$charset = $params['charset'] ?? 'utf-8';
		if ($mime === 'text/plain' && $disposition !== 'attachment') {
			if ($this->textBody === '') {
				$this->textBody = $this->toUtf8($decoded, $charset);
			}
		} elseif ($mime === 'text/html') {
			if ($this->htmlBody === '') {
				$this->htmlBody = $this->toUtf8($decoded, $charset);
			}
		} elseif ($fileName !== '') {
			$this->addAttachment($fileName, $mime, $decoded);
		}
	}

	private function addAttachment(string $name, string $mime, string $content): void {
		if (count($this->attachments) >= self::MAX_ATTACHMENTS
			|| strlen($content) === 0
			|| strlen($content) > self::MAX_ATTACHMENT_SIZE) {
			return;
		}
		$this->attachments[] = [
			'name' => $name !== '' ? $name : 'attachment',
			'mime' => $mime,
			'content' => $content,
		];
	}

	/**
	 * @return array{0: array<string, string>, 1: string}
	 */
	private function splitHeadersBody(string $raw): array {
		$raw = ltrim($raw, "\r\n");
		$separator = strpos($raw, "\r\n\r\n");
		$sepLength = 4;
		if ($separator === false) {
			$separator = strpos($raw, "\n\n");
			$sepLength = 2;
		}
		if ($separator === false) {
			return [$this->parseHeaders($raw), ''];
		}
		return [
			$this->parseHeaders(substr($raw, 0, $separator)),
			substr($raw, $separator + $sepLength),
		];
	}

	/**
	 * @return array<string, string> lowercased header name => raw value (unfolded)
	 */
	private function parseHeaders(string $block): array {
		$headers = [];
		$current = null;
		foreach (preg_split('/\r\n|\n/', $block) ?: [] as $line) {
			if ($line === '') {
				continue;
			}
			if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
				$headers[$current] .= ' ' . trim($line);
				continue;
			}
			$colon = strpos($line, ':');
			if ($colon === false) {
				continue;
			}
			$current = strtolower(trim(substr($line, 0, $colon)));
			$value = trim(substr($line, $colon + 1));
			// First occurrence wins for the headers we care about
			if (!isset($headers[$current])) {
				$headers[$current] = $value;
			} else {
				$current = null;
			}
		}
		return $headers;
	}

	/**
	 * @return array{0: string, 1: array<string, string>} [mime, params]
	 */
	private function parseContentType(string $value): array {
		$parts = explode(';', $value);
		$mime = strtolower(trim(array_shift($parts) ?? 'text/plain'));
		$params = [];
		$continuations = [];
		foreach ($parts as $part) {
			if (!preg_match('/^\s*([^=*]+)(\*\d+)?(\*)?\s*=\s*(.*)$/s', trim($part), $m)) {
				continue;
			}
			$name = strtolower(trim($m[1]));
			$value = trim($m[4]);
			if ($value !== '' && $value[0] === '"') {
				$value = stripslashes(trim($value, '"'));
			}
			if ($m[3] === '*' || ($m[2] !== '' && str_contains($part, '*='))) {
				// RFC 2231: charset'lang'percent-encoded
				if (preg_match("/^([^']*)'[^']*'(.*)$/s", $value, $enc)) {
					$value = $this->toUtf8(rawurldecode($enc[2]), $enc[1] !== '' ? $enc[1] : 'utf-8');
				} else {
					$value = rawurldecode($value);
				}
			}
			if ($m[2] !== '') {
				$continuations[$name][(int)trim($m[2], '*')] = $value;
			} else {
				$params[$name] = $value;
			}
		}
		foreach ($continuations as $name => $chunks) {
			ksort($chunks);
			$params[$name] = implode('', $chunks);
		}
		return [$mime, $params];
	}

	/**
	 * @return string[]
	 */
	private function splitMultipart(string $body, string $boundary): array {
		$parts = explode('--' . $boundary, $body);
		// First chunk is the preamble, a chunk starting with "--" ends the message
		array_shift($parts);
		$result = [];
		foreach ($parts as $part) {
			if (str_starts_with(ltrim($part), '--') || trim($part) === '' || str_starts_with($part, '--')) {
				continue;
			}
			$result[] = ltrim($part, "\r\n");
		}
		return $result;
	}

	private function decodeTransferEncoding(string $body, string $encoding): string {
		return match ($encoding) {
			'base64' => (string)base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
			'quoted-printable' => quoted_printable_decode($body),
			default => $body,
		};
	}

	/**
	 * @param array<string, string> $headers
	 * @param array<string, string> $contentTypeParams
	 */
	private function fileNameFor(array $headers, array $contentTypeParams): string {
		$disposition = $headers['content-disposition'] ?? '';
		if ($disposition !== '') {
			[, $params] = $this->parseContentType('x/x;' . substr($disposition, (int)strpos($disposition, ';') + 1));
			if (str_contains($disposition, ';') && ($params['filename'] ?? '') !== '') {
				return $this->decodeHeader($params['filename']);
			}
		}
		if (($contentTypeParams['name'] ?? '') !== '') {
			return $this->decodeHeader($contentTypeParams['name']);
		}
		return '';
	}

	private function decodeHeader(string $value): string {
		if ($value === '') {
			return '';
		}
		// Only run the RFC 2047 decoder when an encoded word is present:
		// iconv_mime_decode mangles plain non-ASCII input (e.g. RFC 2231
		// file names that are already UTF-8).
		if (!str_contains($value, '=?')) {
			return trim($value);
		}
		$decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
		return trim($decoded !== false ? $decoded : $value);
	}

	/**
	 * @return array{0: string, 1: string} [display name, email]
	 */
	private function parseAddress(string $value): array {
		$value = $this->decodeHeader($value);
		if (preg_match('/^\s*(?:"?([^"<]*)"?\s*)?<([^>]+)>\s*$/', $value, $m)) {
			return [trim($m[1]), trim($m[2])];
		}
		if (preg_match('/[^\s;,]+@[^\s;,]+/', $value, $m)) {
			return ['', trim($m[0], '<>')];
		}
		return [trim($value), ''];
	}

	/**
	 * @return string[] lowercased addresses
	 */
	private function extractAddresses(string $value): array {
		if ($value === '') {
			return [];
		}
		preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+/i', $this->decodeHeader($value), $matches);
		return array_map('strtolower', $matches[0]);
	}

	private function toUtf8(string $text, string $charset): string {
		$charset = strtoupper(trim($charset)) ?: 'UTF-8';
		if ($charset === 'UTF-8' || $charset === 'US-ASCII') {
			// Ensure valid UTF-8 either way
			return (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}
		$converted = @iconv($charset, 'UTF-8//IGNORE', $text);
		if ($converted === false) {
			$converted = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
		}
		return (string)$converted;
	}

	private function htmlToText(string $html): string {
		if ($html === '') {
			return '';
		}
		$html = (string)preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $html);
		$html = (string)preg_replace('/<br\s*\/?>/i', "\n", $html);
		$html = (string)preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $html);
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return (string)preg_replace("/\n{3,}/", "\n\n", $text);
	}
}
