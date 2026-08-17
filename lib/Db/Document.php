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
 * @method string getName()
 * @method void setName(string $name)
 * @method string getMime()
 * @method void setMime(string $mime)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getFileKey()
 * @method void setFileKey(string $fileKey)
 * @method ?string getUploadedBy()
 * @method void setUploadedBy(?string $uploadedBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Document extends Entity implements \JsonSerializable {
	protected int $candidateId = 0;
	protected string $name = '';
	protected string $mime = 'application/octet-stream';
	protected int $size = 0;
	protected string $fileKey = '';
	protected ?string $uploadedBy = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('candidateId', 'integer');
		$this->addType('size', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'candidateId' => $this->candidateId,
			'name' => $this->name,
			'mime' => $this->mime,
			'size' => $this->size,
			'uploadedBy' => $this->uploadedBy,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
