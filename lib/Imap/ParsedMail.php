<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Imap;

/**
 * The parts of an incoming application mail that ingestion cares about.
 */
class ParsedMail {
	/**
	 * @param string[] $recipients all To/Cc/Delivered-To addresses, lowercased
	 * @param array<int, array{name: string, mime: string, content: string}> $attachments
	 * @param string $messageId the raw Message-ID header, '' when the mail has none
	 * @param array<string, string> $headers top-level headers used to classify the mail
	 *                                       (see {@see MimeParser::CLASSIFICATION_HEADERS}),
	 *                                       lowercased names, first occurrence, unfolded
	 * @param string $contentType the lowercased top-level mime type
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $fromName,
		public readonly string $fromEmail,
		public readonly array $recipients,
		public readonly string $textBody,
		public readonly array $attachments,
		public readonly string $messageId = '',
		public readonly array $headers = [],
		public readonly string $contentType = 'text/plain',
	) {
	}

	public function header(string $name): string {
		return $this->headers[strtolower($name)] ?? '';
	}
}
