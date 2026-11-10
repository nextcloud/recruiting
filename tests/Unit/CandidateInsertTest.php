<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\DocumentMapper;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Db\VoteMapper;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\OfferService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\PoolService;
use OCA\Recruiting\Service\PortalService;
use OCA\Recruiting\Service\StageService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\ICommentsManager;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Every new candidate gets its self-service token at insert time, and a
 * referral carries the referrer.
 */
class CandidateInsertTest extends TestCase {
	public function testInsertSetsPortalTokenAndReferrer(): void {
		$mapper = $this->createMock(CandidateMapper::class);
		$mapper->expects($this->exactly(2))->method('insert')->willReturnCallback(static function (Candidate $c): Candidate {
			$c->setId(7);
			return $c;
		});
		$portal = $this->createMock(PortalService::class);
		$portal->method('newToken')->willReturn(str_repeat('t', 48));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-11-10T10:00:00Z'));

		$service = new CandidateService(
			$mapper,
			$this->createMock(OpeningMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->createMock(AssignmentMapper::class),
			$this->createMock(DocumentMapper::class),
			$this->createMock(VoteMapper::class),
			$this->createMock(InterviewMapper::class),
			$this->createMock(InterviewAttendeeMapper::class),
			$this->createMock(OfferMapper::class),
			$this->createMock(OfferService::class),
			$this->createMock(PermissionService::class),
			$this->createMock(DocumentService::class),
			$this->createMock(InterviewService::class),
			$this->createMock(MailService::class),
			$this->createMock(PoolService::class),
			$this->createMock(StageService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(TimelineEventMapper::class),
			$this->createMock(NotificationService::class),
			$this->createMock(ActivityPublisher::class),
			$this->createMock(ICommentsManager::class),
			$this->createMock(IUserManager::class),
			$time,
			$this->createMock(IL10N::class),
			$portal,
		);

		$candidate = $service->insertCandidate(3, 'Grace', 'grace@example.org', '', Candidate::SOURCE_REFERRAL, 'bob', 'bob');
		$this->assertSame(str_repeat('t', 48), $candidate->getPortalToken());
		$this->assertSame('bob', $candidate->getReferredBy());
		$this->assertSame(Candidate::SOURCE_REFERRAL, $candidate->getSource());
		$this->assertArrayNotHasKey('portalToken', $candidate->jsonSerialize());

		$plain = $service->insertCandidate(null, 'Ada', '', '', Candidate::SOURCE_MANUAL, 'hr');
		$this->assertNull($plain->getReferredBy());
	}
}
