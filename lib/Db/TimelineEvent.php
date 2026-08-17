<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Append-only per-candidate timeline entry.
 *
 * @method int getCandidateId()
 * @method void setCandidateId(int $candidateId)
 * @method string getType()
 * @method void setType(string $type)
 * @method ?string getActorUid()
 * @method void setActorUid(?string $actorUid)
 * @method ?string getData()
 * @method void setData(?string $data)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class TimelineEvent extends Entity implements \JsonSerializable {
	public const TYPE_CREATED = 'created';
	public const TYPE_STAGE_CHANGED = 'stage_changed';
	public const TYPE_OPENING_ASSIGNED = 'opening_assigned';
	public const TYPE_SCREENER_ASSIGNED = 'screener_assigned';
	public const TYPE_SCREENER_REMOVED = 'screener_removed';
	public const TYPE_VOTE_CAST = 'vote_cast';
	public const TYPE_DOCUMENT_ADDED = 'document_added';
	public const TYPE_MAIL_SENT = 'mail_sent';
	public const TYPE_MAIL_RECEIVED = 'mail_received';
	public const TYPE_INTERVIEW_PROPOSED = 'interview_proposed';
	public const TYPE_INTERVIEW_CONFIRMED = 'interview_confirmed';
	public const TYPE_INTERVIEW_CANCELLED = 'interview_cancelled';
	public const TYPE_OFFER_CREATED = 'offer_created';
	public const TYPE_OFFER_SUBMITTED = 'offer_submitted';
	public const TYPE_OFFER_APPROVED = 'offer_approved';
	public const TYPE_OFFER_APPROVAL_DECLINED = 'offer_approval_declined';
	public const TYPE_OFFER_SENT = 'offer_sent';
	public const TYPE_OFFER_RESPONSE = 'offer_response';
	public const TYPE_OFFER_EXPIRED = 'offer_expired';
	public const TYPE_OFFER_WITHDRAWN = 'offer_withdrawn';
	public const TYPE_ANONYMIZED = 'anonymized';
	public const TYPE_POOL_INVITED = 'pool_invited';
	public const TYPE_POOL_JOINED = 'pool_joined';
	public const TYPE_POOL_COPIED = 'pool_copied';

	protected int $candidateId = 0;
	protected string $type = '';
	protected ?string $actorUid = null;
	protected ?string $data = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('candidateId', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function getDecodedData(): array {
		if ($this->data === null || $this->data === '') {
			return [];
		}
		$decoded = json_decode($this->data, true);
		return is_array($decoded) ? $decoded : [];
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'type' => $this->type,
			'actorUid' => $this->actorUid,
			'data' => $this->getDecodedData(),
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
