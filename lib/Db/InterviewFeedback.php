<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\SnowflakeAwareEntity;

/**
 * One interviewer's verdict on one interview round. The verdict uses the
 * screening vote values (Vote::VALUES).
 *
 * @method int getInterviewId()
 * @method void setInterviewId(int $interviewId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getVerdict()
 * @method void setVerdict(string $verdict)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class InterviewFeedback extends SnowflakeAwareEntity implements \JsonSerializable {
	protected int $interviewId = 0;
	protected string $uid = '';
	protected string $verdict = '';
	protected ?string $notes = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('interviewId', 'integer');
		$this->addType('updatedAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'uid' => $this->uid,
			'verdict' => $this->verdict,
			'notes' => $this->notes ?? '',
			'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
