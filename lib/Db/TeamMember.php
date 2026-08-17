<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getOpeningId()
 * @method void setOpeningId(int $openingId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getRole()
 * @method void setRole(string $role)
 */
class TeamMember extends Entity implements \JsonSerializable {
	public const ROLE_MANAGER = 'manager';
	public const ROLE_INTERVIEWER = 'interviewer';
	public const ROLE_OBSERVER = 'observer';

	public const ROLES = [self::ROLE_MANAGER, self::ROLE_INTERVIEWER, self::ROLE_OBSERVER];

	protected int $openingId = 0;
	protected string $uid = '';
	protected string $role = '';

	public function __construct() {
		$this->addType('openingId', 'integer');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'uid' => $this->uid,
			'role' => $this->role,
		];
	}
}
