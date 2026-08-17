<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getType()
 * @method void setType(string $type)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getSubject()
 * @method void setSubject(string $subject)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method bool getIsDefault()
 * @method void setIsDefault(bool $isDefault)
 */
class MailTemplate extends Entity implements \JsonSerializable {
	public const TYPE_INTERVIEW_INVITE = 'interview_invite';
	public const TYPE_REJECTION = 'rejection';
	public const TYPE_RECEIPT = 'receipt_confirmation';
	public const TYPE_OFFER = 'offer';

	public const TYPES = [
		self::TYPE_INTERVIEW_INVITE,
		self::TYPE_REJECTION,
		self::TYPE_RECEIPT,
		self::TYPE_OFFER,
	];

	protected string $type = '';
	protected string $name = '';
	protected string $subject = '';
	protected string $body = '';
	protected bool $isDefault = false;

	public function __construct() {
		$this->addType('isDefault', 'boolean');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'type' => $this->type,
			'name' => $this->name,
			'subject' => $this->subject,
			'body' => $this->body,
			'isDefault' => $this->isDefault,
			'builtin' => false,
		];
	}
}
