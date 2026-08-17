<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getInterviewId()
 * @method void setInterviewId(int $interviewId)
 * @method string getUid()
 * @method void setUid(string $uid)
 */
class InterviewAttendee extends Entity implements \JsonSerializable {
	protected int $interviewId = 0;
	protected string $uid = '';

	public function __construct() {
		$this->addType('interviewId', 'integer');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return ['uid' => $this->uid];
	}
}
