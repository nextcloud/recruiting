<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Imap;

/**
 * Minimal, dependency-free IMAP4rev1 client — just what mail ingestion
 * needs: LOGIN, SELECT, UID SEARCH UNSEEN, UID FETCH (RFC822.SIZE,
 * BODY.PEEK[HEADER], BODY.PEEK[]),
 * UID STORE \Seen, and moving a message into a quarantine folder
 * (CAPABILITY, LIST, CREATE, STATUS, UID MOVE or UID COPY + EXPUNGE).
 * Credentials are sent as literals, so any characters are safe.
 */
class ImapClient {
	/** @var resource|null */
	private $socket = null;
	private int $tagCounter = 0;
	/** @var string[]|null uppercased capability atoms, cached per connection */
	private ?array $capabilities = null;

	public function __construct(
		private string $host,
		private int $port,
		private string $security = 'ssl',
		private int $timeout = 15,
	) {
	}

	/**
	 * @throws ImapException
	 */
	public function connect(): void {
		$this->socket = $this->openSocket(($this->security === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port);
		$this->capabilities = null;

		$greeting = $this->readLine();
		if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
			throw new ImapException('Unexpected IMAP greeting: ' . trim($greeting));
		}

		if ($this->security === 'starttls') {
			$this->command('STARTTLS');
			if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
				throw new ImapException('STARTTLS negotiation failed');
			}
		}
	}

	/**
	 * @return resource
	 * @throws ImapException
	 */
	protected function openSocket(string $remote) {
		$context = stream_context_create();
		$socket = @stream_socket_client($remote, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
		if ($socket === false) {
			throw new ImapException('Connection to ' . $this->host . ':' . $this->port . ' failed: ' . $error);
		}
		stream_set_timeout($socket, $this->timeout);
		return $socket;
	}

	/**
	 * @throws ImapException
	 */
	public function login(string $user, string $password): void {
		$tag = $this->nextTag();
		$this->write($tag . ' LOGIN {' . strlen($user) . "}\r\n");
		$this->expectContinuation();
		$this->write($user . ' {' . strlen($password) . "}\r\n");
		$this->expectContinuation();
		$this->write($password . "\r\n");
		$this->readUntilTagged($tag);
		// Servers may advertise more after authentication
		$this->capabilities = null;
	}

	/**
	 * @return string[] uppercased capability atoms (e.g. IMAP4REV1, MOVE, UIDPLUS)
	 * @throws ImapException
	 */
	public function capabilities(): array {
		if ($this->capabilities === null) {
			$this->capabilities = [];
			foreach ($this->command('CAPABILITY') as $line) {
				if (preg_match('/^\* CAPABILITY\s+(.*)$/i', $line, $m)) {
					$atoms = preg_split('/\s+/', strtoupper(trim($m[1]))) ?: [];
					$this->capabilities = array_values(array_filter($atoms, static fn (string $a): bool => $a !== ''));
				}
			}
		}
		return $this->capabilities;
	}

	/**
	 * @throws ImapException
	 */
	public function hasCapability(string $capability): bool {
		return in_array(strtoupper($capability), $this->capabilities(), true);
	}

	/**
	 * Make sure a mailbox exists and return the name it is reachable under.
	 * Servers that only allow folders below INBOX (Courier, older Cyrus)
	 * reject the top-level CREATE; the folder is then created as
	 * INBOX<delimiter><name>.
	 *
	 * @throws ImapException when the mailbox can neither be found nor created
	 */
	public function ensureMailbox(string $name): string {
		if ($this->mailboxExists($name)) {
			return $name;
		}
		try {
			$this->command('CREATE ' . $this->quote($name));
			return $name;
		} catch (ImapException $topLevelFailure) {
			$delimiter = $this->hierarchyDelimiter();
			if ($delimiter === null) {
				throw $topLevelFailure;
			}
			$nested = 'INBOX' . $delimiter . $name;
			if (!$this->mailboxExists($nested)) {
				$this->command('CREATE ' . $this->quote($nested));
			}
			return $nested;
		}
	}

	/**
	 * @throws ImapException
	 */
	public function mailboxExists(string $name): bool {
		foreach ($this->command('LIST "" ' . $this->quote($name)) as $line) {
			if (preg_match('/^\* LIST\b/i', $line)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Number of messages in a mailbox, without selecting it.
	 *
	 * @throws ImapException e.g. when the mailbox does not exist
	 */
	public function messageCount(string $mailbox): int {
		foreach ($this->command('STATUS ' . $this->quote($mailbox) . ' (MESSAGES)') as $line) {
			if (preg_match('/^\* STATUS\b.*\(.*\bMESSAGES\s+(\d+)/i', $line, $m)) {
				return (int)$m[1];
			}
		}
		return 0;
	}

	/**
	 * Move a message from the selected mailbox into another one: UID MOVE
	 * (RFC 6851) when available, else UID COPY + \Deleted + UID EXPUNGE
	 * (RFC 4315) or, without UIDPLUS, a plain EXPUNGE.
	 *
	 * @throws ImapException
	 */
	public function moveMessage(int $uid, string $mailbox): void {
		if ($this->hasCapability('MOVE')) {
			$this->command('UID MOVE ' . $uid . ' ' . $this->quote($mailbox));
			return;
		}
		$this->command('UID COPY ' . $uid . ' ' . $this->quote($mailbox));
		$this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\Deleted)');
		$this->command($this->hasCapability('UIDPLUS') ? 'UID EXPUNGE ' . $uid : 'EXPUNGE');
	}

	/**
	 * @throws ImapException
	 */
	public function selectInbox(): void {
		$this->command('SELECT INBOX');
	}

	/**
	 * @return int[] UIDs of unseen messages
	 * @throws ImapException
	 */
	public function searchUnseen(): array {
		$lines = $this->command('UID SEARCH UNSEEN');
		foreach ($lines as $line) {
			if (preg_match('/^\* SEARCH\b(.*)$/i', $line, $m)) {
				return array_values(array_filter(array_map('intval', preg_split('/\s+/', trim($m[1])) ?: [])));
			}
		}
		return [];
	}

	/**
	 * Fetch the complete raw message without setting \Seen.
	 *
	 * @throws ImapException
	 */
	public function fetchMessage(int $uid): string {
		return $this->fetchLiteral($uid, 'BODY.PEEK[]');
	}

	/**
	 * Fetch only the header block of a message, without setting \Seen.
	 *
	 * @throws ImapException
	 */
	public function fetchHeader(int $uid): string {
		return $this->fetchLiteral($uid, 'BODY.PEEK[HEADER]');
	}

	/**
	 * The size of a message in bytes, known before downloading it.
	 *
	 * @throws ImapException
	 */
	public function fetchSize(int $uid): int {
		foreach ($this->command('UID FETCH ' . $uid . ' (RFC822.SIZE)') as $line) {
			if (preg_match('/\bRFC822\.SIZE\s+(\d+)/i', $line, $m)) {
				return (int)$m[1];
			}
		}
		throw new ImapException('FETCH returned no size for UID ' . $uid);
	}

	/**
	 * @throws ImapException
	 */
	private function fetchLiteral(int $uid, string $item): string {
		$tag = $this->nextTag();
		$this->write($tag . ' UID FETCH ' . $uid . ' (' . $item . ")\r\n");

		$literal = null;
		while (true) {
			$line = $this->readLine();
			if (str_starts_with($line, $tag . ' ')) {
				$this->assertOk($tag, $line);
				break;
			}
			if ($literal === null && preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
				$literal = $this->readBytes((int)$m[1]);
			}
		}
		if ($literal === null) {
			throw new ImapException('FETCH returned no message body for UID ' . $uid);
		}
		return $literal;
	}

	/**
	 * @throws ImapException
	 */
	public function markSeen(int $uid): void {
		$this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\Seen)');
	}

	/**
	 * The hierarchy delimiter from LIST "" "", or null for a flat namespace.
	 *
	 * @throws ImapException
	 */
	private function hierarchyDelimiter(): ?string {
		foreach ($this->command('LIST "" ""') as $line) {
			if (preg_match('/^\* LIST\s+\([^)]*\)\s+"((?:[^"\\\\]|\\\\.)*)"/i', $line, $m)) {
				return stripslashes($m[1]);
			}
		}
		return null;
	}

	private function quote(string $mailbox): string {
		return '"' . addcslashes($mailbox, '"\\') . '"';
	}

	public function logout(): void {
		if ($this->socket === null) {
			return;
		}
		try {
			$this->command('LOGOUT');
		} catch (ImapException) {
			// closing anyway
		}
		$socket = $this->socket;
		$this->socket = null;
		fclose($socket);
	}

	public function __destruct() {
		if ($this->socket !== null) {
			@fclose($this->socket);
		}
	}

	/**
	 * Send a simple command and collect all untagged lines until the
	 * tagged OK.
	 *
	 * @return string[] untagged response lines
	 * @throws ImapException
	 */
	private function command(string $command): array {
		$tag = $this->nextTag();
		$this->write($tag . ' ' . $command . "\r\n");
		return $this->readUntilTagged($tag);
	}

	/**
	 * @return string[]
	 * @throws ImapException
	 */
	private function readUntilTagged(string $tag): array {
		$lines = [];
		while (true) {
			$line = $this->readLine();
			if (str_starts_with($line, $tag . ' ')) {
				$this->assertOk($tag, $line);
				return $lines;
			}
			// Swallow literals inside untagged responses we do not care about
			if (preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
				$this->readBytes((int)$m[1]);
			}
			$lines[] = rtrim($line, "\r\n");
		}
	}

	/**
	 * @throws ImapException
	 */
	private function expectContinuation(): void {
		$line = $this->readLine();
		if (!str_starts_with($line, '+')) {
			throw new ImapException('IMAP login rejected: ' . trim($line));
		}
	}

	/**
	 * @throws ImapException
	 */
	private function assertOk(string $tag, string $line): void {
		if (!str_starts_with($line, $tag . ' OK')) {
			throw new ImapException('IMAP command failed: ' . trim($line));
		}
	}

	/**
	 * @throws ImapException
	 */
	private function readLine(): string {
		$line = fgets($this->requireSocket());
		if ($line === false) {
			throw new ImapException('Connection closed by IMAP server');
		}
		return $line;
	}

	/**
	 * @throws ImapException
	 */
	private function readBytes(int $count): string {
		$data = '';
		$socket = $this->requireSocket();
		while (strlen($data) < $count) {
			$chunk = fread($socket, min(8192, $count - strlen($data)));
			if ($chunk === false || $chunk === '') {
				throw new ImapException('Connection closed while reading literal');
			}
			$data .= $chunk;
		}
		return $data;
	}

	/**
	 * @throws ImapException
	 */
	private function write(string $data): void {
		if (@fwrite($this->requireSocket(), $data) === false) {
			throw new ImapException('Failed to write to IMAP server');
		}
	}

	private function nextTag(): string {
		return 'A' . str_pad((string)(++$this->tagCounter), 4, '0', STR_PAD_LEFT);
	}

	/**
	 * @return resource
	 * @throws ImapException
	 */
	private function requireSocket() {
		if ($this->socket === null) {
			throw new ImapException('Not connected');
		}
		return $this->socket;
	}
}
