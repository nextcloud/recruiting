<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

/**
 * Rewrites the free-text properties of the events in an iCalendar object
 * (RFC 5545) so that nothing identifying the candidate is left: SUMMARY,
 * DESCRIPTION and LOCATION are replaced, links and comments are dropped.
 * Dates, organizer, attendees and the UID stay untouched.
 *
 * Works on the text form on purpose: the app ships no iCalendar library,
 * and the server's Sabre classes are not part of OCP.
 */
class IcsScrubber {
	/** Properties that may carry the candidate's name or the Talk link */
	private const DROPPED = ['URL', 'ATTACH', 'COMMENT', 'CONFERENCE', 'X-ALT-DESC'];

	/**
	 * @param ?string $location null removes the location
	 */
	public static function neutralize(string $ics, string $summary, string $description, ?string $location): string {
		$lines = self::unfold($ics);
		$result = [];
		$components = [];
		foreach ($lines as $line) {
			$name = self::propertyName($line);
			if ($name === 'BEGIN') {
				$components[] = strtoupper(trim(substr($line, 6)));
				$result[] = $line;
				continue;
			}
			if ($name === 'END') {
				array_pop($components);
				$result[] = $line;
				continue;
			}
			if (!in_array('VEVENT', $components, true)) {
				$result[] = $line;
				continue;
			}

			$inAlarm = end($components) === 'VALARM';
			if (in_array($name, self::DROPPED, true)) {
				continue;
			}
			if ($name === 'SUMMARY') {
				$result[] = 'SUMMARY:' . self::escape($summary);
			} elseif ($name === 'DESCRIPTION') {
				$result[] = 'DESCRIPTION:' . self::escape($inAlarm ? $summary : $description);
			} elseif ($name === 'LOCATION') {
				if ($location !== null && $location !== '') {
					$result[] = 'LOCATION:' . self::escape($location);
				}
			} else {
				$result[] = $line;
			}
		}
		return implode("\r\n", array_map(self::fold(...), $result)) . "\r\n";
	}

	/**
	 * @return string[] logical lines, continuation lines joined
	 */
	private static function unfold(string $ics): array {
		$lines = [];
		foreach (preg_split('/\r\n|\n|\r/', $ics) ?: [] as $raw) {
			if ($raw === '') {
				continue;
			}
			if (($raw[0] === ' ' || $raw[0] === "\t") && $lines !== []) {
				$lines[count($lines) - 1] .= substr($raw, 1);
			} else {
				$lines[] = $raw;
			}
		}
		return $lines;
	}

	private static function propertyName(string $line): string {
		$end = strcspn($line, ';:');
		$name = strtoupper(substr($line, 0, $end));
		// A group prefix ("item1.SUMMARY") does not change the property
		$dot = strrpos($name, '.');
		return $dot === false ? $name : substr($name, $dot + 1);
	}

	private static function escape(string $text): string {
		return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $text);
	}

	/**
	 * Lines longer than 75 octets are folded without splitting a UTF-8
	 * character.
	 */
	private static function fold(string $line): string {
		if (strlen($line) <= 75) {
			return $line;
		}
		$parts = [];
		$current = '';
		$limit = 75;
		foreach (mb_str_split($line) as $char) {
			if (strlen($current) + strlen($char) > $limit) {
				$parts[] = $current;
				$current = '';
				$limit = 74;
			}
			$current .= $char;
		}
		$parts[] = $current;
		return implode("\r\n ", $parts);
	}
}
