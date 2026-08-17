<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Offer;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\OfferService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\StageService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The permission matrix around offers: every mutating entry point must be
 * gated — either through manage permission on the candidate or through the
 * designated-approver check. These tests pin the guards so a refactor
 * cannot silently drop one (spec §4.6).
 */
class OfferServiceGuardTest extends TestCase {
	private OfferMapper $offerMapper;
	private CandidateMapper $candidateMapper;
	private PermissionService $permissions;
	private OfferService $service;

	protected function setUp(): void {
		$this->offerMapper = $this->createMock(OfferMapper::class);
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => $text);

		$this->service = new OfferService(
			$this->offerMapper,
			$this->candidateMapper,
			$this->permissions,
			$this->createMock(StageService::class),
			$this->createMock(MailService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(ActivityPublisher::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
		);
	}

	private function primeOffer(string $status, string $approver = 'frank'): void {
		$offer = new Offer();
		$offer->setId(5);
		$offer->setCandidateId(42);
		$offer->setStatus($status);
		$offer->setApproverUid($approver);
		$this->offerMapper->method('find')->with(5)->willReturn($offer);

		$candidate = new Candidate();
		$candidate->setId(42);
		$candidate->setOpeningId(7);
		$this->candidateMapper->method('find')->with(42)->willReturn($candidate);
	}

	private function denyManage(): void {
		$this->permissions->method('assertCanManageCandidate')
			->willThrowException(new NotPermittedException('nope'));
	}

	public function testCreateRequiresManagePermission(): void {
		$candidate = new Candidate();
		$candidate->setId(42);
		$this->candidateMapper->method('find')->willReturn($candidate);
		$this->denyManage();

		$this->expectException(NotPermittedException::class);
		$this->service->create('interviewer', 42, ['jobTitle' => 'Engineer']);
	}

	public function testUpdateRequiresManagePermission(): void {
		$this->primeOffer(Offer::STATUS_DRAFT);
		$this->denyManage();

		$this->expectException(NotPermittedException::class);
		$this->service->update('interviewer', 5, ['jobTitle' => 'Engineer']);
	}

	public function testSubmitRequiresManagePermission(): void {
		$this->primeOffer(Offer::STATUS_DRAFT);
		$this->denyManage();

		$this->expectException(NotPermittedException::class);
		$this->service->submit('interviewer', 5, 'frank');
	}

	public function testSendRequiresManagePermission(): void {
		$this->primeOffer(Offer::STATUS_APPROVED);
		$this->denyManage();

		$this->expectException(NotPermittedException::class);
		$this->service->send('interviewer', 5, null, null);
	}

	public function testRespondRequiresManagePermission(): void {
		$this->primeOffer(Offer::STATUS_SENT);
		$this->denyManage();

		$this->expectException(NotPermittedException::class);
		$this->service->respond('interviewer', 5, Offer::STATUS_ACCEPTED);
	}

	public function testWithdrawRequiresManagePermission(): void {
		$this->primeOffer(Offer::STATUS_SENT);
		$this->denyManage();

		$this->expectException(NotPermittedException::class);
		$this->service->withdraw('interviewer', 5);
	}

	public function testOnlyTheDesignatedApproverMayApprove(): void {
		$this->primeOffer(Offer::STATUS_PENDING_APPROVAL, approver: 'frank');

		$this->expectException(NotPermittedException::class);
		$this->service->approve('somebody-else', 5);
	}

	public function testOnlyTheDesignatedApproverMayDecline(): void {
		$this->primeOffer(Offer::STATUS_PENDING_APPROVAL, approver: 'frank');

		$this->expectException(NotPermittedException::class);
		$this->service->declineApproval('somebody-else', 5, 'not my call');
	}
}
