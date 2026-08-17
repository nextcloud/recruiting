<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method ?int getOpeningId()
 * @method void setOpeningId(?int $openingId)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getPhone()
 * @method void setPhone(string $phone)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getStage()
 * @method void setStage(string $stage)
 * @method ?\DateTime getStageChangedAt()
 * @method void setStageChangedAt(?\DateTime $stageChangedAt)
 * @method ?string getAiSummary()
 * @method void setAiSummary(?string $aiSummary)
 * @method ?string getRejectionReason()
 * @method void setRejectionReason(?string $rejectionReason)
 * @method ?\DateTime getRejectedAt()
 * @method void setRejectedAt(?\DateTime $rejectedAt)
 * @method ?\DateTime getHiredAt()
 * @method void setHiredAt(?\DateTime $hiredAt)
 * @method ?\DateTime getWithdrawnAt()
 * @method void setWithdrawnAt(?\DateTime $withdrawnAt)
 * @method bool getPoolMember()
 * @method void setPoolMember(bool $poolMember)
 * @method ?\DateTime getPoolConsentAt()
 * @method void setPoolConsentAt(?\DateTime $poolConsentAt)
 * @method ?string getPoolConsentToken()
 * @method void setPoolConsentToken(?string $poolConsentToken)
 * @method ?string getReplyToken()
 * @method void setReplyToken(?string $replyToken)
 * @method ?\DateTime getAnonymizedAt()
 * @method void setAnonymizedAt(?\DateTime $anonymizedAt)
 * @method ?int getDuplicateOf()
 * @method void setDuplicateOf(?int $duplicateOf)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Candidate extends Entity implements \JsonSerializable {
	public const STAGE_NEW = 'new';
	public const STAGE_SCREENING = 'screening';
	public const STAGE_INTERVIEW = 'interview';
	public const STAGE_OFFER = 'offer';
	public const STAGE_HIRED = 'hired';
	public const STAGE_REJECTED = 'rejected';
	public const STAGE_WITHDRAWN = 'withdrawn';

	/** Board columns, in pipeline order */
	public const ACTIVE_STAGES = [
		self::STAGE_NEW,
		self::STAGE_SCREENING,
		self::STAGE_INTERVIEW,
		self::STAGE_OFFER,
	];

	public const TERMINAL_STAGES = [
		self::STAGE_HIRED,
		self::STAGE_REJECTED,
		self::STAGE_WITHDRAWN,
	];

	public const SOURCE_EMAIL = 'email';
	public const SOURCE_MANUAL = 'manual';
	public const SOURCE_POOL = 'pool';

	public const REJECTION_REASONS = [
		'not_qualified',
		'better_candidate',
		'position_filled',
		'withdrawn',
		'other',
	];

	protected ?int $openingId = null;
	protected string $displayName = '';
	protected string $email = '';
	protected string $phone = '';
	protected string $source = self::SOURCE_MANUAL;
	protected string $stage = self::STAGE_NEW;
	protected ?\DateTime $stageChangedAt = null;
	protected ?string $aiSummary = null;
	protected ?string $rejectionReason = null;
	protected ?\DateTime $rejectedAt = null;
	protected ?\DateTime $hiredAt = null;
	protected ?\DateTime $withdrawnAt = null;
	protected bool $poolMember = false;
	protected ?\DateTime $poolConsentAt = null;
	protected ?string $poolConsentToken = null;
	protected ?string $replyToken = null;
	protected ?\DateTime $anonymizedAt = null;
	protected ?int $duplicateOf = null;
	protected ?string $createdBy = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('openingId', 'integer');
		$this->addType('duplicateOf', 'integer');
		$this->addType('poolMember', 'boolean');
		$this->addType('stageChangedAt', 'datetime');
		$this->addType('rejectedAt', 'datetime');
		$this->addType('hiredAt', 'datetime');
		$this->addType('withdrawnAt', 'datetime');
		$this->addType('poolConsentAt', 'datetime');
		$this->addType('anonymizedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
	}

	public function isTerminal(): bool {
		return in_array($this->stage, self::TERMINAL_STAGES, true);
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'openingId' => $this->openingId,
			'displayName' => $this->displayName,
			'email' => $this->email,
			'phone' => $this->phone,
			'source' => $this->source,
			'stage' => $this->stage,
			'stageChangedAt' => $this->stageChangedAt?->format(\DateTimeInterface::ATOM),
			'aiSummary' => $this->aiSummary,
			'rejectionReason' => $this->rejectionReason,
			'rejectedAt' => $this->rejectedAt?->format(\DateTimeInterface::ATOM),
			'hiredAt' => $this->hiredAt?->format(\DateTimeInterface::ATOM),
			'withdrawnAt' => $this->withdrawnAt?->format(\DateTimeInterface::ATOM),
			'duplicateOf' => $this->duplicateOf,
			'poolMember' => $this->poolMember,
			'poolConsentAt' => $this->poolConsentAt?->format(\DateTimeInterface::ATOM),
			'anonymizedAt' => $this->anonymizedAt?->format(\DateTimeInterface::ATOM),
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
