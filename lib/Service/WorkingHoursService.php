<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * A user's working hours from Personal settings → Availability.
 *
 * The dav app keeps them as the CalDAV property `calendar-availability` of
 * the user's schedule inbox, in the `properties` table; there is no public
 * API to read them. The dav app's own user-status automation reads the
 * same row the same way.
 */
class WorkingHoursService {
	private const PROPERTY = '{urn:ietf:params:xml:ns:caldav}calendar-availability';

	/** @var array<string, ?WorkingHours> */
	private array $cache = [];

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The user's weekly working hours, or null when they have not set any.
	 */
	public function forUser(string $uid): ?WorkingHours {
		if (!array_key_exists($uid, $this->cache)) {
			$this->cache[$uid] = $this->load($uid);
		}
		return $this->cache[$uid];
	}

	private function load(string $uid): ?WorkingHours {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('propertyvalue')
				->from('properties')
				->where($qb->expr()->eq('userid', $qb->createNamedParameter($uid)))
				->andWhere($qb->expr()->eq('propertypath', $qb->createNamedParameter('calendars/' . $uid . '/inbox')))
				->andWhere($qb->expr()->eq('propertyname', $qb->createNamedParameter(self::PROPERTY)))
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$value = $result->fetchOne();
			$result->closeCursor();
		} catch (\Exception $e) {
			$this->logger->warning('Recruiting: could not read the working hours of ' . $uid . ': ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
		if (is_resource($value)) {
			$value = stream_get_contents($value);
		}
		if (!is_string($value) || $value === '') {
			return null;
		}
		return WorkingHours::fromVAvailability($value);
	}
}
