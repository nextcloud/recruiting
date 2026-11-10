<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Imap\ImapClient;
use OCA\Recruiting\Imap\ImapException;
use OCA\Recruiting\Tests\Unit\Fixtures\FakeImapStream;
use PHPUnit\Framework\TestCase;

/**
 * The quarantine commands of the IMAP client against a scripted server.
 */
class ImapClientTest extends TestCase {
	private ImapClient $client;

	protected function setUp(): void {
		FakeImapStream::register();
		$this->client = new class('imap.example.com', 993) extends ImapClient {
			#[\Override]
			protected function openSocket(string $remote) {
				$socket = fopen(FakeImapStream::PROTOCOL . '://server', 'r+');
				if ($socket === false) {
					throw new ImapException('fake stream failed');
				}
				return $socket;
			}
		};
		$this->client->connect();
	}

	public function testCapabilitiesAreParsedAndCached(): void {
		FakeImapStream::$replies['CAPABILITY'] = ['* CAPABILITY IMAP4rev1 Move UIDPLUS IDLE'];

		$this->assertSame(['IMAP4REV1', 'MOVE', 'UIDPLUS', 'IDLE'], $this->client->capabilities());
		$this->assertTrue($this->client->hasCapability('move'));
		$this->assertFalse($this->client->hasCapability('CONDSTORE'));
		$this->assertSame(['CAPABILITY'], FakeImapStream::$commands);
	}

	public function testLoginRefreshesTheCapabilities(): void {
		FakeImapStream::$replies['CAPABILITY'] = ['* CAPABILITY IMAP4rev1'];
		$this->assertFalse($this->client->hasCapability('MOVE'));

		$this->client->login('u', 'p');
		$this->assertSame('LOGIN {literal} {literal}', end(FakeImapStream::$commands));
		FakeImapStream::$replies['CAPABILITY'] = ['* CAPABILITY IMAP4rev1 MOVE'];
		$this->assertTrue($this->client->hasCapability('MOVE'));
	}

	public function testUidMoveWhenAdvertised(): void {
		FakeImapStream::$replies['CAPABILITY'] = ['* CAPABILITY IMAP4rev1 MOVE'];

		$this->client->moveMessage(42, 'Recruiting-Failed');

		$this->assertSame(['CAPABILITY', 'UID MOVE 42 "Recruiting-Failed"'], FakeImapStream::$commands);
	}

	public function testCopyDeleteAndUidExpungeWithUidplus(): void {
		FakeImapStream::$replies['CAPABILITY'] = ['* CAPABILITY IMAP4rev1 UIDPLUS'];

		$this->client->moveMessage(7, 'Recruiting-Failed');

		$this->assertSame([
			'CAPABILITY',
			'UID COPY 7 "Recruiting-Failed"',
			'UID STORE 7 +FLAGS.SILENT (\Deleted)',
			'UID EXPUNGE 7',
		], FakeImapStream::$commands);
	}

	public function testCopyDeleteAndExpungeWithoutUidplus(): void {
		FakeImapStream::$replies['CAPABILITY'] = ['* CAPABILITY IMAP4rev1'];

		$this->client->moveMessage(7, 'Recruiting-Failed');

		$this->assertSame('EXPUNGE', end(FakeImapStream::$commands));
		$this->assertContains('UID COPY 7 "Recruiting-Failed"', FakeImapStream::$commands);
	}

	public function testAFailedCopyDoesNotDeleteTheMessage(): void {
		FakeImapStream::$replies['CAPABILITY'] = ['* CAPABILITY IMAP4rev1 UIDPLUS'];
		FakeImapStream::$replies['UID COPY 7 "Recruiting-Failed"'] = ['NO [TRYCREATE] no such mailbox'];

		try {
			$this->client->moveMessage(7, 'Recruiting-Failed');
			$this->fail('expected ImapException');
		} catch (ImapException) {
		}
		$this->assertNotContains('UID STORE 7 +FLAGS.SILENT (\Deleted)', FakeImapStream::$commands);
	}

	public function testExistingMailboxIsNotCreated(): void {
		FakeImapStream::$replies['LIST "" "Recruiting-Failed"'] = ['* LIST (\HasNoChildren) "/" "Recruiting-Failed"'];

		$this->assertSame('Recruiting-Failed', $this->client->ensureMailbox('Recruiting-Failed'));
		$this->assertSame(['LIST "" "Recruiting-Failed"'], FakeImapStream::$commands);
	}

	public function testMissingMailboxIsCreated(): void {
		$this->assertSame('Recruiting-Failed', $this->client->ensureMailbox('Recruiting-Failed'));
		$this->assertSame(['LIST "" "Recruiting-Failed"', 'CREATE "Recruiting-Failed"'], FakeImapStream::$commands);
	}

	public function testFallsBackBelowInboxWhenTopLevelIsRefused(): void {
		FakeImapStream::$replies['CREATE "Recruiting-Failed"'] = ['NO [CANNOT] top-level mailboxes not allowed'];
		FakeImapStream::$replies['LIST "" ""'] = ['* LIST (\Noselect) "." ""'];

		$this->assertSame('INBOX.Recruiting-Failed', $this->client->ensureMailbox('Recruiting-Failed'));
		$this->assertSame('CREATE "INBOX.Recruiting-Failed"', end(FakeImapStream::$commands));
	}

	public function testFlatNamespaceRethrowsTheCreateFailure(): void {
		FakeImapStream::$replies['CREATE "Recruiting-Failed"'] = ['NO permission denied'];
		FakeImapStream::$replies['LIST "" ""'] = ['* LIST (\Noselect) NIL ""'];

		$this->expectException(ImapException::class);
		$this->expectExceptionMessage('permission denied');
		$this->client->ensureMailbox('Recruiting-Failed');
	}

	public function testMessageCount(): void {
		FakeImapStream::$replies['STATUS "Recruiting-Failed" (MESSAGES)'] = ['* STATUS "Recruiting-Failed" (MESSAGES 3)'];

		$this->assertSame(3, $this->client->messageCount('Recruiting-Failed'));
	}

	public function testMessageCountOfMissingMailboxThrows(): void {
		FakeImapStream::$replies['STATUS "Nope" (MESSAGES)'] = ['NO mailbox does not exist'];

		$this->expectException(ImapException::class);
		$this->client->messageCount('Nope');
	}

	public function testMailboxNamesAreQuoted(): void {
		$this->client->mailboxExists('A "quoted" \\ name');
		$this->assertSame(['LIST "" "A \\"quoted\\" \\\\ name"'], FakeImapStream::$commands);
	}
}
