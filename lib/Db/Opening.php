<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getDepartment()
 * @method void setDepartment(string $department)
 * @method string getLocation()
 * @method void setLocation(string $location)
 * @method string getEmploymentType()
 * @method void setEmploymentType(string $employmentType)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method ?string getRequirements()
 * @method void setRequirements(?string $requirements)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getMailSlug()
 * @method void setMailSlug(string $mailSlug)
 * @method ?string getTalkToken()
 * @method void setTalkToken(?string $talkToken)
 * @method bool getAutoAck()
 * @method void setAutoAck(bool $autoAck)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method ?\DateTime getClosedAt()
 * @method void setClosedAt(?\DateTime $closedAt)
 */
class Opening extends Entity implements \JsonSerializable {
	public const STATUS_OPEN = 'open';
	public const STATUS_ON_HOLD = 'on_hold';
	public const STATUS_CLOSED = 'closed';

	protected string $title = '';
	protected string $department = '';
	protected string $location = '';
	protected string $employmentType = '';
	protected ?string $description = null;
	protected ?string $requirements = null;
	protected string $status = self::STATUS_OPEN;
	protected string $mailSlug = '';
	protected ?string $talkToken = null;
	protected bool $autoAck = false;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $closedAt = null;

	public function __construct() {
		$this->addType('autoAck', 'boolean');
		$this->addType('createdAt', 'datetime');
		$this->addType('closedAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'title' => $this->title,
			'department' => $this->department,
			'location' => $this->location,
			'employmentType' => $this->employmentType,
			'description' => $this->description ?? '',
			'requirements' => $this->requirements ?? '',
			'status' => $this->status,
			'mailSlug' => $this->mailSlug,
			'autoAck' => $this->autoAck,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'closedAt' => $this->closedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
