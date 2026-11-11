<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A proposed time slot the candidate can pick from.
 *
 * @method int getInterviewId()
 * @method void setInterviewId(int $interviewId)
 * @method \DateTime getStartAt()
 * @method void setStartAt(\DateTime $startAt)
 * @method \DateTime getEndAt()
 * @method void setEndAt(\DateTime $endAt)
 * @method bool getChosen()
 * @method void setChosen(bool $chosen)
 * @method bool getUnavailable()
 * @method void setUnavailable(bool $unavailable)
 */
class InterviewSlot extends Entity implements \JsonSerializable {
	protected int $interviewId = 0;
	protected ?\DateTime $startAt = null;
	protected ?\DateTime $endAt = null;
	protected bool $chosen = false;
	/** Somebody on the panel turned out to be busy when the candidate picked it */
	protected bool $unavailable = false;

	public function __construct() {
		$this->addType('interviewId', 'integer');
		$this->addType('chosen', 'boolean');
		$this->addType('unavailable', 'boolean');
		$this->addType('startAt', 'datetime');
		$this->addType('endAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'startAt' => $this->startAt?->format(\DateTimeInterface::ATOM),
			'endAt' => $this->endAt?->format(\DateTimeInterface::ATOM),
			'chosen' => $this->chosen,
			'unavailable' => $this->unavailable,
		];
	}
}
