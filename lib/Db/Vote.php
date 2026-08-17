<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCandidateId()
 * @method void setCandidateId(int $candidateId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getVote()
 * @method void setVote(string $vote)
 * @method ?string getComment()
 * @method void setComment(?string $comment)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class Vote extends Entity implements \JsonSerializable {
	public const YES = 'yes';
	public const MAYBE = 'maybe';
	public const NO = 'no';

	public const VALUES = [self::YES, self::MAYBE, self::NO];

	protected int $candidateId = 0;
	protected string $uid = '';
	protected string $vote = '';
	protected ?string $comment = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('candidateId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'uid' => $this->uid,
			'vote' => $this->vote,
			'comment' => $this->comment ?? '',
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
