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

	public function testClassificationHeadersAreExposed(): void {
		$raw = "Return-Path: <>\r\n"
			. "Authentication-Results: mx.company.com;\r\n"
			. "\tspf=pass smtp.mailfrom=a@example.com;\r\n"
			. "\tdmarc=pass header.from=example.com\r\n"
			. "Authentication-Results: relay.example.net; dmarc=fail\r\n"
			. "From: Mail Delivery System <MAILER-DAEMON@mx.example.com>\r\n"
			. "To: jobs@company.com\r\n"
			. "Subject: Undelivered Mail Returned to Sender\r\n"
			. "Auto-Submitted: auto-replied\r\n"
			. "Precedence: bulk\r\n"
			. "List-Id: <jobs.example.org>\r\n"
			. "X-Autoreply: yes\r\n"
			. "X-Autorespond: yes\r\n"
			. "X-Auto-Response-Suppress: All\r\n"
			. "X-Unrelated: dropped\r\n"
			. "Content-Type: Multipart/Report; report-type=delivery-status; boundary=\"R\"\r\n"
			. "\r\n"
			. "--R\r\n"
			. "Content-Type: text/plain\r\n"
			. "\r\n"
			. "Delivery failed.\r\n"
			. "--R--\r\n";

		$mail = $this->parser->parse($raw);
		$this->assertSame('multipart/report', $mail->contentType);
		$this->assertSame('<>', $mail->header('Return-Path'));
		// Topmost occurrence wins, folded lines are unfolded
		$this->assertSame('mx.company.com; spf=pass smtp.mailfrom=a@example.com; dmarc=pass header.from=example.com', $mail->header('authentication-results'));
		$this->assertSame('auto-replied', $mail->header('auto-submitted'));
		$this->assertSame('bulk', $mail->header('precedence'));
		$this->assertSame('<jobs.example.org>', $mail->header('list-id'));
		$this->assertSame('yes', $mail->header('x-autoreply'));
		$this->assertSame('yes', $mail->header('x-autorespond'));
		$this->assertSame('All', $mail->header('x-auto-response-suppress'));
		$this->assertArrayNotHasKey('x-unrelated', $mail->headers);
		$this->assertSame('', $mail->header('x-unrelated'));
		$this->assertSame('mailer-daemon@mx.example.com', $mail->fromEmail);
		$this->assertStringContainsString('Delivery failed.', $mail->textBody);
	}

	public function testPlainMailHasNoClassificationHeaders(): void {
		$mail = $this->parser->parse("From: a@example.com\r\nSubject: Hi\r\n\r\nHello\r\n");
		$this->assertSame([], $mail->headers);
		$this->assertSame('text/plain', $mail->contentType);
	}

	public function testOversizedClassificationHeaderIsClamped(): void {
		$mail = $this->parser->parse("From: a@example.com\r\nList-Id: " . str_repeat('x', 5000) . "\r\n\r\nHello\r\n");
		$this->assertSame(2000, strlen($mail->header('list-id')));
	}

	public function testRawLatin1HeadersAndBodyBecomeUtf8(): void {
		$raw = "From: J\xF6rg M\xFCller <joerg@example.com>\r\n"
			. "Subject: Bewerbung f\xFCr die Stelle\r\n"
			. "Message-ID: <caf\xE9@example.com>\r\n"
			. "\r\n"
			. "Gr\xFC\xDFe\r\n";

		$mail = $this->parser->parse($raw);

		$this->assertSame('Jörg Müller', $mail->fromName);
		$this->assertSame('Bewerbung für die Stelle', $mail->subject);
		$this->assertSame('<café@example.com>', $mail->messageId);
		$this->assertSame('Grüße', $mail->textBody);
		$this->assertNotFalse(json_encode([$mail->fromName, $mail->subject, $mail->textBody], JSON_THROW_ON_ERROR));
	}

	public function testWindows1252CharactersAreKept(): void {
		// 0x80 is the euro sign and 0x93/0x94 are curly quotes in Windows-1252
		$mail = $this->parser->parse("Subject: \x93Salary\x94 \x80 50k\r\n\r\nx");

		$this->assertSame('“Salary” € 50k', $mail->subject);
	}

	public function testEncodedWordsInLatin1AndQEncoding(): void {
		$raw = "From: =?ISO-8859-1?Q?J=F6rg_M=FCller?= <joerg@example.com>\r\n"
			. 'Subject: =?iso-8859-1?B?' . base64_encode("Bewerbung f\xFCr") . "?= Backend\r\n"
			. "\r\nx";

		$mail = $this->parser->parse($raw);

		$this->assertSame('Jörg Müller', $mail->fromName);
		$this->assertSame('Bewerbung für Backend', $mail->subject);
	}

	public function testAdjacentEncodedWordsJoinEvenWhenACharacterIsSplit(): void {
		$bytes = 'Grüße';
		// The UTF-8 bytes of "ü" end up in two encoded words
		$first = base64_encode(substr($bytes, 0, 3));
		$second = base64_encode(substr($bytes, 3));
		$mail = $this->parser->parse("Subject: =?UTF-8?B?{$first}?=\r\n =?UTF-8?B?{$second}?=\r\n\r\nx");

		$this->assertSame('Grüße', $mail->subject);
	}

	public function testRawUtf8HeadersStayAsTheyAre(): void {
		$mail = $this->parser->parse("Subject: Bewerbung für Zoë\r\n\r\nx");

		$this->assertSame('Bewerbung für Zoë', $mail->subject);
	}

	public function testDeclaredCharsetsAreHonoured(): void {
		$raw = "Subject: x\r\n"
			. "Content-Type: text/plain; charset=\"iso-8859-15\"\r\n"
			. "\r\n"
			. "Preis: 100 \xA4\r\n";

		// 0xA4 is the euro sign in ISO-8859-15 (and ¤ in Latin-1)
		$this->assertSame('Preis: 100 €', $this->parser->parse($raw)->textBody);
	}

	public function testInvalidBytesInADeclaredUtf8BodyAreScrubbed(): void {
		$raw = "Subject: x\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nok \xFF\xFE done\r\n";

		$body = $this->parser->parse($raw)->textBody;

		$this->assertTrue(mb_check_encoding($body, 'UTF-8'));
		$this->assertStringStartsWith('ok ', $body);
		$this->assertStringEndsWith(' done', $body);
	}

	public function testAnUnknownCharsetFallsBackToAGuess(): void {
		$raw = "Subject: x\r\nContent-Type: text/plain; charset=x-made-up\r\n\r\nM\xFCller\r\n";

		$this->assertSame('Müller', $this->parser->parse($raw)->textBody);
	}
}
