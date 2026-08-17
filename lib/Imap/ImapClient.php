<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Imap;

/**
 * Minimal, dependency-free IMAP4rev1 client — just what mail ingestion
 * needs: LOGIN, SELECT, UID SEARCH UNSEEN, UID FETCH BODY.PEEK[] and
 * UID STORE \Seen. Credentials are sent as literals, so any characters
 * are safe.
 */
class ImapClient {
	/** @var resource|null */
	private $socket = null;
	private int $tagCounter = 0;

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
		$remote = ($this->security === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
		$context = stream_context_create();
		$socket = @stream_socket_client($remote, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
		if ($socket === false) {
			throw new ImapException('Connection to ' . $this->host . ':' . $this->port . ' failed: ' . $error);
		}
		stream_set_timeout($socket, $this->timeout);
		$this->socket = $socket;

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
		$tag = $this->nextTag();
		$this->write($tag . ' UID FETCH ' . $uid . " (BODY.PEEK[])\r\n");

		$message = null;
		while (true) {
			$line = $this->readLine();
			if (str_starts_with($line, $tag . ' ')) {
				$this->assertOk($tag, $line);
				break;
			}
			if ($message === null && preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
				$message = $this->readBytes((int)$m[1]);
			}
		}
		if ($message === null) {
			throw new ImapException('FETCH returned no message body for UID ' . $uid);
		}
		return $message;
	}

	/**
	 * @throws ImapException
	 */
	public function markSeen(int $uid): void {
		$this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\Seen)');
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
