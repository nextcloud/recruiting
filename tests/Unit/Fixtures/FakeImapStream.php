<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit\Fixtures;

/**
 * A scripted IMAP server as a PHP stream wrapper ("fakeimap://"). Every
 * command line the client writes is logged and answered from `$replies`:
 * command text (without tag) => untagged response lines. A reply line
 * starting with "NO " or "BAD " becomes the tagged completion instead of OK.
 * Commands without a scripted reply complete with OK. Literals ({n}) are
 * accepted with a continuation and logged as "{literal}".
 */
class FakeImapStream {
	public const PROTOCOL = 'fakeimap';

	/** @var array<string, list<string>> */
	public static array $replies = [];
	/** @var list<string> commands received, without tags */
	public static array $commands = [];

	/** @var resource|null */
	public $context;
	private string $outgoing = '';
	private string $incoming = '';
	private string $pending = '';
	private int $literalBytes = 0;

	public static function register(): void {
		if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::PROTOCOL, self::class);
		}
		self::$replies = [];
		self::$commands = [];
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->outgoing = "* OK fake IMAP ready\r\n";
		return true;
	}

	public function stream_read(int $count): string {
		$chunk = substr($this->outgoing, 0, $count);
		$this->outgoing = substr($this->outgoing, strlen($chunk));
		return $chunk;
	}

	public function stream_write(string $data): int {
		$this->incoming .= $data;
		while (true) {
			if ($this->literalBytes > 0) {
				if (strlen($this->incoming) < $this->literalBytes) {
					break;
				}
				$this->incoming = substr($this->incoming, $this->literalBytes);
				$this->pending .= '{literal}';
				$this->literalBytes = 0;
			}
			$end = strpos($this->incoming, "\r\n");
			if ($end === false) {
				break;
			}
			$line = $this->pending . substr($this->incoming, 0, $end);
			$this->incoming = substr($this->incoming, $end + 2);
			if (preg_match('/\{(\d+)\}$/', $line, $m)) {
				$this->pending = substr($line, 0, -strlen($m[0]));
				$this->literalBytes = (int)$m[1];
				$this->outgoing .= "+ go ahead\r\n";
				continue;
			}
			$this->pending = '';
			$this->answer($line);
		}
		return strlen($data);
	}

	public function stream_eof(): bool {
		return false;
	}

	public function stream_close(): void {
	}

	public function stream_set_option(int $option, int $arg1, ?int $arg2): bool {
		return false;
	}

	private function answer(string $line): void {
		[$tag, $command] = array_pad(explode(' ', $line, 2), 2, '');
		self::$commands[] = $command;
		$status = 'OK done';
		foreach (self::$replies[$command] ?? [] as $reply) {
			if (str_starts_with($reply, 'NO ') || str_starts_with($reply, 'BAD ')) {
				$status = $reply;
				continue;
			}
			$this->outgoing .= $reply . "\r\n";
		}
		$this->outgoing .= $tag . ' ' . $status . "\r\n";
	}
}
