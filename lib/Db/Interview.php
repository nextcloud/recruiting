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
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?\DateTime getStartAt()
 * @method void setStartAt(?\DateTime $startAt)
 * @method ?\DateTime getEndAt()
 * @method void setEndAt(?\DateTime $endAt)
 * @method int getDurationMin()
 * @method void setDurationMin(int $durationMin)
 * @method bool getIsVideo()
 * @method void setIsVideo(bool $isVideo)
 * @method string getLocation()
 * @method void setLocation(string $location)
 * @method string getTalkLink()
 * @method void setTalkLink(string $talkLink)
 * @method string getTalkToken()
 * @method void setTalkToken(string $talkToken)
 * @method string getCalendarUid()
 * @method void setCalendarUid(string $calendarUid)
 * @method string getPublicToken()
 * @method void setPublicToken(string $publicToken)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Interview extends Entity implements \JsonSerializable {
	public const STATUS_PROPOSED = 'proposed';
	public const STATUS_CONFIRMED = 'confirmed';
	public const STATUS_DONE = 'done';
	public const STATUS_CANCELLED = 'cancelled';

	protected int $candidateId = 0;
	protected string $title = '';
	protected string $status = self::STATUS_PROPOSED;
	protected ?\DateTime $startAt = null;
	protected ?\DateTime $endAt = null;
	protected int $durationMin = 60;
	protected bool $isVideo = false;
	protected string $location = '';
	protected string $talkLink = '';
	protected string $talkToken = '';
	protected string $calendarUid = '';
	protected string $publicToken = '';
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('candidateId', 'integer');
		$this->addType('durationMin', 'integer');
		$this->addType('isVideo', 'boolean');
		$this->addType('startAt', 'datetime');
		$this->addType('endAt', 'datetime');
		$this->addType('createdAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'candidateId' => $this->candidateId,
			'title' => $this->title,
			'status' => $this->status,
			'startAt' => $this->startAt?->format(\DateTimeInterface::ATOM),
			'endAt' => $this->endAt?->format(\DateTimeInterface::ATOM),
			'durationMin' => $this->durationMin,
			'isVideo' => $this->isVideo,
			'location' => $this->location,
			'talkLink' => $this->talkLink,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
