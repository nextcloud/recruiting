<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A screener assigned to review a candidate.
 *
 * @method int getCandidateId()
 * @method void setCandidateId(int $candidateId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getAssignedBy()
 * @method void setAssignedBy(string $assignedBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Assignment extends Entity implements \JsonSerializable {
	protected int $candidateId = 0;
	protected string $uid = '';
	protected string $assignedBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('candidateId', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'uid' => $this->uid,
			'assignedBy' => $this->assignedBy,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
