<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

/**
 * Weekly working hours, read from the VAVAILABILITY (RFC 7953) that the
 * Nextcloud availability settings store: one AVAILABLE component per time
 * range with a weekly RRULE listing its days, in the user's timezone.
 *
 * Text based like IcsScrubber, as the app ships no iCalendar library.
 */
class WorkingHours {
	private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

	/**
	 * Days are ISO weekdays (1 = Monday), start and end are minutes after
	 * midnight in the window's timezone.
	 *
	 * @param list<array{days: list<int>, start: int, end: int, timezone: \DateTimeZone}> $windows
	 */
	public function __construct(
		private array $windows,
	) {
	}

	/**
	 * Monday to Friday between two full hours.
	 */
	public static function weekdays(int $startHour, int $endHour, \DateTimeZone $timezone): self {
		return new self([['days' => [1, 2, 3, 4, 5], 'start' => $startHour * 60, 'end' => $endHour * 60, 'timezone' => $timezone]]);
	}

	/**
	 * The weekly ranges of a VAVAILABILITY object, or null when it holds
	 * none (the user cleared their working hours, or only one-off or
	 * non-weekly ranges are set).
	 */
	public static function fromVAvailability(string $ics): ?self {
		$components = [];
		$defaultTimezone = null;
		$current = null;
		$windows = [];
		foreach (IcsScrubber::unfold($ics) as $line) {
			$name = IcsScrubber::propertyName($line);
			$value = substr($line, strcspn($line, ':') + 1);
			if ($name === 'BEGIN') {
				$components[] = strtoupper(trim($value));
				if (end($components) === 'AVAILABLE') {
					$current = [];
				}
				continue;
			}
			if ($name === 'END') {
				if (array_pop($components) === 'AVAILABLE' && $current !== null) {
					$window = self::window($current, $defaultTimezone);
					if ($window !== null) {
						$windows[] = $window;
					}
					$current = null;
				}
				continue;
			}
			if ($name === 'TZID' && end($components) === 'VTIMEZONE') {
				$defaultTimezone = self::timezone(trim($value));
			} elseif ($current !== null && end($components) === 'AVAILABLE' && in_array($name, ['DTSTART', 'DTEND', 'RRULE'], true)) {
				$current[$name] = $line;
			}
		}
		return $windows === [] ? null : new self($windows);
	}

	/**
	 * Whether [$start, $end) lies within one of the ranges.
	 */
	public function contains(\DateTimeInterface $start, \DateTimeInterface $end): bool {
		foreach ($this->windows as $window) {
			$localStart = \DateTimeImmutable::createFromInterface($start)->setTimezone($window['timezone']);
			if (!in_array((int)$localStart->format('N'), $window['days'], true)) {
				continue;
			}
			$startMinute = (int)$localStart->format('G') * 60 + (int)$localStart->format('i');
			$endMinute = $startMinute + intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
			if ($startMinute >= $window['start'] && $endMinute <= $window['end']) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, string> $properties
	 * @return ?array{days: list<int>, start: int, end: int, timezone: \DateTimeZone}
	 */
	private static function window(array $properties, ?\DateTimeZone $defaultTimezone): ?array {
		if (!isset($properties['DTSTART'], $properties['DTEND'], $properties['RRULE'])) {
			return null;
		}
		$start = self::localTime($properties['DTSTART'], $defaultTimezone);
		$end = self::localTime($properties['DTEND'], $defaultTimezone);
		if ($start === null || $end === null) {
			return null;
		}
		$endMinute = $end['minute'] === 0 && $end['date'] !== $start['date'] ? 1440 : $end['minute'];
		if ($endMinute <= $start['minute']) {
			return null;
		}

		$rule = [];
		foreach (explode(';', strtoupper(substr($properties['RRULE'], strcspn($properties['RRULE'], ':') + 1))) as $part) {
			[$key, $partValue] = array_pad(explode('=', $part, 2), 2, '');
			$rule[trim($key)] = trim($partValue);
		}
		if (($rule['FREQ'] ?? '') !== 'WEEKLY') {
			return null;
		}
		$days = [];
		foreach (array_filter(explode(',', $rule['BYDAY'] ?? '')) as $day) {
			$day = substr($day, -2);
			if (isset(self::DAYS[$day])) {
				$days[] = self::DAYS[$day];
			}
		}
		if ($days === []) {
			$days[] = $start['weekday'];
		}
		return ['days' => array_values(array_unique($days)), 'start' => $start['minute'], 'end' => $endMinute, 'timezone' => $start['timezone']];
	}

	/**
	 * The wall-clock time of a DTSTART/DTEND line in its own timezone.
	 *
	 * @return ?array{date: string, minute: int, weekday: int, timezone: \DateTimeZone}
	 */
	private static function localTime(string $line, ?\DateTimeZone $defaultTimezone): ?array {
		$colon = strcspn($line, ':');
		$value = trim(substr($line, $colon + 1));
		if (preg_match('/^(\d{8})T(\d{2})(\d{2})\d{2}(Z?)$/', $value, $m) !== 1) {
			return null;
		}
		$timezone = $defaultTimezone ?? new \DateTimeZone('UTC');
		if ($m[4] === 'Z') {
			$timezone = new \DateTimeZone('UTC');
		} elseif (preg_match('/;TZID=("?)([^";:]+)\1/i', substr($line, 0, $colon), $tz) === 1) {
			$timezone = self::timezone($tz[2]) ?? $timezone;
		}
		$date = \DateTimeImmutable::createFromFormat('!Ymd', $m[1], $timezone);
		if ($date === false) {
			return null;
		}
		return [
			'date' => $m[1],
			'minute' => (int)$m[2] * 60 + (int)$m[3],
			'weekday' => (int)$date->format('N'),
			'timezone' => $timezone,
		];
	}

	private static function timezone(string $id): ?\DateTimeZone {
		try {
			return new \DateTimeZone($id);
		} catch (\Exception) {
			return null;
		}
	}
}
