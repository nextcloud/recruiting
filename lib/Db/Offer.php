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
 * @method string getJobTitle()
 * @method void setJobTitle(string $jobTitle)
 * @method string getSalaryAmount()
 * @method void setSalaryAmount(string $salaryAmount)
 * @method string getSalaryCurrency()
 * @method void setSalaryCurrency(string $salaryCurrency)
 * @method string getSalaryPeriod()
 * @method void setSalaryPeriod(string $salaryPeriod)
 * @method ?\DateTime getStartDate()
 * @method void setStartDate(?\DateTime $startDate)
 * @method ?\DateTime getValidUntil()
 * @method void setValidUntil(?\DateTime $validUntil)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getApproverUid()
 * @method void setApproverUid(?string $approverUid)
 * @method ?\DateTime getApprovedAt()
 * @method void setApprovedAt(?\DateTime $approvedAt)
 * @method ?\DateTime getRespondedAt()
 * @method void setRespondedAt(?\DateTime $respondedAt)
 * @method bool getExpiryNotified()
 * @method void setExpiryNotified(bool $expiryNotified)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Offer extends Entity implements \JsonSerializable {
	public const STATUS_DRAFT = 'draft';
	public const STATUS_PENDING_APPROVAL = 'pending_approval';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_SENT = 'sent';
	public const STATUS_ACCEPTED = 'accepted';
	public const STATUS_DECLINED = 'declined';
	public const STATUS_NEGOTIATING = 'negotiating';
	public const STATUS_EXPIRED = 'expired';
	public const STATUS_WITHDRAWN = 'withdrawn';

	/**
	 * The offer state machine (spec §4.6). Anything not listed here is an
	 * invalid transition.
	 */
	public const TRANSITIONS = [
		self::STATUS_DRAFT => [self::STATUS_PENDING_APPROVAL, self::STATUS_WITHDRAWN],
		self::STATUS_PENDING_APPROVAL => [self::STATUS_APPROVED, self::STATUS_DRAFT, self::STATUS_WITHDRAWN],
		self::STATUS_APPROVED => [self::STATUS_SENT, self::STATUS_WITHDRAWN],
		self::STATUS_SENT => [self::STATUS_ACCEPTED, self::STATUS_DECLINED, self::STATUS_NEGOTIATING, self::STATUS_EXPIRED, self::STATUS_WITHDRAWN],
		self::STATUS_NEGOTIATING => [self::STATUS_ACCEPTED, self::STATUS_DECLINED, self::STATUS_EXPIRED, self::STATUS_WITHDRAWN],
		self::STATUS_ACCEPTED => [],
		self::STATUS_DECLINED => [],
		self::STATUS_EXPIRED => [],
		self::STATUS_WITHDRAWN => [],
	];

	/** States in which the candidate can still respond */
	public const OPEN_STATES = [self::STATUS_SENT, self::STATUS_NEGOTIATING];

	/** States that are over and done with */
	public const TERMINAL_STATES = [self::STATUS_ACCEPTED, self::STATUS_DECLINED, self::STATUS_EXPIRED, self::STATUS_WITHDRAWN];

	protected int $candidateId = 0;
	protected string $jobTitle = '';
	protected string $salaryAmount = '';
	protected string $salaryCurrency = 'EUR';
	protected string $salaryPeriod = 'year';
	protected ?\DateTime $startDate = null;
	protected ?\DateTime $validUntil = null;
	protected ?string $notes = null;
	protected string $status = self::STATUS_DRAFT;
	protected ?string $approverUid = null;
	protected ?\DateTime $approvedAt = null;
	protected ?\DateTime $respondedAt = null;
	protected bool $expiryNotified = false;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('candidateId', 'integer');
		$this->addType('expiryNotified', 'boolean');
		$this->addType('startDate', 'datetime');
		$this->addType('validUntil', 'datetime');
		$this->addType('approvedAt', 'datetime');
		$this->addType('respondedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
	}

	public function canTransitionTo(string $status): bool {
		return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
	}

	public function isTerminal(): bool {
		return in_array($this->status, self::TERMINAL_STATES, true);
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'candidateId' => $this->candidateId,
			'jobTitle' => $this->jobTitle,
			'salaryAmount' => $this->salaryAmount,
			'salaryCurrency' => $this->salaryCurrency,
			'salaryPeriod' => $this->salaryPeriod,
			'startDate' => $this->startDate?->format('Y-m-d'),
			'validUntil' => $this->validUntil?->format('Y-m-d'),
			'notes' => $this->notes ?? '',
			'status' => $this->status,
			'approverUid' => $this->approverUid,
			'approvedAt' => $this->approvedAt?->format(\DateTimeInterface::ATOM),
			'respondedAt' => $this->respondedAt?->format(\DateTimeInterface::ATOM),
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
