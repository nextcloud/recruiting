<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Imap\MimeParser;
use PHPUnit\Framework\TestCase;

class MimeParserTest extends TestCase {
	private MimeParser $parser;

	protected function setUp(): void {
		$this->parser = new MimeParser();
	}

	public function testSimplePlainTextMail(): void {
		$raw = "From: Jane Doe <jane@example.com>\r\n"
			. "To: jobs@company.com\r\n"
			. "Subject: Application for Backend Engineer\r\n"
			. "Content-Type: text/plain; charset=utf-8\r\n"
			. "\r\n"
			. "Hello,\r\n\r\nI would like to apply.\r\n";

		$mail = $this->parser->parse($raw);
		$this->assertSame('Application for Backend Engineer', $mail->subject);
		$this->assertSame('Jane Doe', $mail->fromName);
		$this->assertSame('jane@example.com', $mail->fromEmail);
		$this->assertContains('jobs@company.com', $mail->recipients);
		$this->assertStringContainsString('I would like to apply.', $mail->textBody);
		$this->assertSame([], $mail->attachments);
	}

	public function testMultipartWithBase64Attachment(): void {
		$pdf = '%PDF-1.4 fake pdf content';
		$raw = "From: applicant@example.org\r\n"
			. "To: jobs+backend-dev@company.com\r\n"
			. "Subject: My application\r\n"
			. "MIME-Version: 1.0\r\n"
			. "Content-Type: multipart/mixed; boundary=\"BOUNDARY123\"\r\n"
			. "\r\n"
			. "--BOUNDARY123\r\n"
			. "Content-Type: text/plain; charset=utf-8\r\n"
			. "\r\n"
			. "Please find my CV attached.\r\n"
			. "--BOUNDARY123\r\n"
			. "Content-Type: application/pdf; name=\"cv.pdf\"\r\n"
			. "Content-Disposition: attachment; filename=\"cv.pdf\"\r\n"
			. "Content-Transfer-Encoding: base64\r\n"
			. "\r\n"
			. chunk_split(base64_encode($pdf))
			. "--BOUNDARY123--\r\n";

		$mail = $this->parser->parse($raw);
		$this->assertSame('', $mail->fromName);
		$this->assertSame('applicant@example.org', $mail->fromEmail);
		$this->assertContains('jobs+backend-dev@company.com', $mail->recipients);
		$this->assertStringContainsString('Please find my CV attached.', $mail->textBody);
		$this->assertCount(1, $mail->attachments);
		$this->assertSame('cv.pdf', $mail->attachments[0]['name']);
		$this->assertSame('application/pdf', $mail->attachments[0]['mime']);
		$this->assertSame($pdf, $mail->attachments[0]['content']);
	}

	public function testEncodedHeadersAndQuotedPrintable(): void {
		$raw = "From: =?UTF-8?B?SsO8cmdlbiBNw7xsbGVy?= <juergen@example.de>\r\n"
			. "To: jobs@company.com\r\n"
			. "Subject: =?UTF-8?Q?Bewerbung_f=C3=BCr_die_Stelle?=\r\n"
			. "Content-Type: text/plain; charset=iso-8859-1\r\n"
			. "Content-Transfer-Encoding: quoted-printable\r\n"
			. "\r\n"
			. "Sch=F6ne Gr=FC=DFe aus M=FCnchen\r\n";

		$mail = $this->parser->parse($raw);
		$this->assertSame('Jürgen Müller', $mail->fromName);
		$this->assertSame('juergen@example.de', $mail->fromEmail);
		$this->assertSame('Bewerbung für die Stelle', $mail->subject);
		$this->assertStringContainsString('Schöne Grüße aus München', $mail->textBody);
	}

	public function testHtmlOnlyMailFallsBackToStrippedText(): void {
		$raw = "From: a@b.com\r\n"
			. "Subject: Hi\r\n"
			. "Content-Type: text/html; charset=utf-8\r\n"
			. "\r\n"
			. "<html><body><p>Hello <b>world</b></p><p>Second &amp; paragraph</p></body></html>\r\n";

		$mail = $this->parser->parse($raw);
		$this->assertStringContainsString('Hello world', $mail->textBody);
		$this->assertStringContainsString('Second & paragraph', $mail->textBody);
		$this->assertStringNotContainsString('<p>', $mail->textBody);
	}

	public function testNestedMultipartAlternative(): void {
		$raw = "From: x@y.com\r\n"
			. "Subject: Nested\r\n"
			. "Content-Type: multipart/mixed; boundary=\"outer\"\r\n"
			. "\r\n"
			. "--outer\r\n"
			. "Content-Type: multipart/alternative; boundary=\"inner\"\r\n"
			. "\r\n"
			. "--inner\r\n"
			. "Content-Type: text/plain\r\n"
			. "\r\n"
			. "plain version\r\n"
			. "--inner\r\n"
			. "Content-Type: text/html\r\n"
			. "\r\n"
			. "<p>html version</p>\r\n"
			. "--inner--\r\n"
			. "--outer\r\n"
			. "Content-Type: image/png; name=\"photo.png\"\r\n"
			. "Content-Disposition: inline; filename=\"photo.png\"\r\n"
			. "Content-Transfer-Encoding: base64\r\n"
			. "\r\n"
			. base64_encode('PNGDATA') . "\r\n"
			. "--outer--\r\n";

		$mail = $this->parser->parse($raw);
		$this->assertStringContainsString('plain version', $mail->textBody);
		$this->assertCount(1, $mail->attachments);
		$this->assertSame('photo.png', $mail->attachments[0]['name']);
		$this->assertSame('PNGDATA', $mail->attachments[0]['content']);
	}

	public function testRfc2231EncodedFilename(): void {
		$raw = "From: a@b.com\r\n"
			. "Subject: RFC2231\r\n"
			. "Content-Type: multipart/mixed; boundary=\"bb\"\r\n"
			. "\r\n"
			. "--bb\r\n"
			. "Content-Type: application/pdf\r\n"
			. "Content-Disposition: attachment; filename*=UTF-8''Lebenslauf%20M%C3%BCller.pdf\r\n"
			. "Content-Transfer-Encoding: base64\r\n"
			. "\r\n"
			. base64_encode('PDF') . "\r\n"
			. "--bb--\r\n";

		$mail = $this->parser->parse($raw);
		$this->assertCount(1, $mail->attachments);
		$this->assertSame('Lebenslauf Müller.pdf', $mail->attachments[0]['name']);
	}

	public function testOversizedAttachmentIsSkipped(): void {
		$raw = "From: a@b.com\r\n"
			. "Subject: big\r\n"
			. "Content-Type: multipart/mixed; boundary=\"bb\"\r\n"
			. "\r\n"
			. "--bb\r\n"
			. "Content-Type: application/pdf; name=\"big.pdf\"\r\n"
			. "Content-Disposition: attachment; filename=\"big.pdf\"\r\n"
			. "\r\n"
			. str_repeat('A', MimeParser::MAX_ATTACHMENT_SIZE + 1) . "\r\n"
			. "--bb--\r\n";

		$mail = $this->parser->parse($raw);
		$this->assertSame([], $mail->attachments);
	}
}
