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
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\OfferService;
use OCA\Recruiting\Service\PermissionService;
use OCA\Recruiting\Service\StageService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Offer transitions: the send claim against double sends, the transaction
 * around each transition, and expiry handling that isolates failures.
 */
class OfferServiceTransitionTest extends TestCase {
	private OfferMapper&MockObject $offerMapper;
	private CandidateMapper&MockObject $candidateMapper;
	private StageService&MockObject $stages;
	private MailService&MockObject $mail;
	private TimelineService&MockObject $timeline;
	private NotificationService&MockObject $notifications;
	private ActivityPublisher&MockObject $activity;
	private IDBConnection&MockObject $db;
	private OfferService $service;
	private Candidate $candidate;

	protected function setUp(): void {
		$this->offerMapper = $this->createMock(OfferMapper::class);
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->candidate = new Candidate();
		$this->candidate->setId(42);
		$this->candidate->setOpeningId(7);
		$this->candidate->setStage(Candidate::STAGE_INTERVIEW);
		$this->candidateMapper->method('find')->willReturnCallback(fn () => $this->candidate);
		$this->stages = $this->createMock(StageService::class);
		$this->mail = $this->createMock(MailService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->activity = $this->createMock(ActivityPublisher::class);
		$this->db = $this->createMock(IDBConnection::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-11-11T09:00:00Z'));

		$this->service = new OfferService(
			$this->offerMapper,
			$this->candidateMapper,
			$this->createMock(PermissionService::class),
			$this->stages,
			$this->mail,
			$this->timeline,
			$this->notifications,
			$this->activity,
			$this->createMock(IUserManager::class),
			$time,
			$l10n,
			$this->db,
			new NullLogger(),
		);
	}

	private function offer(int $id, string $status, ?string $validUntil = null): Offer {
		$offer = new Offer();
		$offer->setId($id);
		$offer->setCandidateId(42);
		$offer->setStatus($status);
		$offer->setCreatedBy('hr');
		$offer->setValidUntil($validUntil !== null ? new \DateTime($validUntil) : null);
		$offer->resetUpdatedFields();
		return $offer;
	}

	public function testSendClaimsTheOfferBeforeMailing(): void {
		$this->offerMapper->method('find')->willReturn($this->offer(5, Offer::STATUS_APPROVED));
		$order = [];
		$this->offerMapper->expects($this->once())->method('claimStatus')
			->with(5, Offer::STATUS_APPROVED, Offer::STATUS_SENT)
			->willReturnCallback(function () use (&$order) {
				$order[] = 'claim';
				return true;
			});
		$this->mail->expects($this->once())->method('sendToCandidate')->willReturnCallback(function () use (&$order): void {
			$order[] = 'mail';
		});
		$this->db->method('beginTransaction')->willReturnCallback(function () use (&$order): void {
			$order[] = 'begin';
		});
		$this->stages->expects($this->once())->method('apply')->with($this->candidate, Candidate::STAGE_OFFER, 'hr')
			->willReturnCallback(function () use (&$order) {
				$order[] = 'stage';
				return true;
			});
		$this->timeline->expects($this->once())->method('record')->with(42, TimelineEvent::TYPE_OFFER_SENT, 'hr');
		$this->db->method('commit')->willReturnCallback(function () use (&$order): void {
			$order[] = 'commit';
		});
		$this->activity->expects($this->once())->method('publish')->with($this->candidate, 'offer_sent', 'hr');

		$result = $this->service->send('hr', 5, 'Your offer', 'Welcome aboard');

		$this->assertSame(['claim', 'mail', 'begin', 'stage', 'commit'], $order);
		$this->assertSame(Offer::STATUS_SENT, $result['status']);
	}

	public function testASecondSendLosesTheClaimAndMailsNothing(): void {
		$this->offerMapper->method('find')->willReturnOnConsecutiveCalls(
			$this->offer(5, Offer::STATUS_APPROVED),
			$this->offer(5, Offer::STATUS_SENT),
		);
		$this->offerMapper->method('claimStatus')->willReturn(false);
		$this->mail->expects($this->never())->method('sendToCandidate');
		$this->stages->expects($this->never())->method('apply');

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('This offer has already been sent');
		$this->service->send('hr', 5, 'Your offer', 'Welcome aboard');
	}

	public function testAClaimLostToAWithdrawalReportsTheTransition(): void {
		$this->offerMapper->method('find')->willReturnOnConsecutiveCalls(
			$this->offer(5, Offer::STATUS_APPROVED),
			$this->offer(5, Offer::STATUS_WITHDRAWN),
		);
		$this->offerMapper->method('claimStatus')->willReturn(false);
		$this->mail->expects($this->never())->method('sendToCandidate');

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('This offer cannot change from "withdrawn" to "sent"');
		$this->service->send('hr', 5, 'Your offer', 'Welcome aboard');
	}

	public function testAFailedMailGivesTheClaimBack(): void {
		$this->offerMapper->method('find')->willReturn($this->offer(5, Offer::STATUS_APPROVED));
		$claims = [];
		$this->offerMapper->method('claimStatus')->willReturnCallback(function (int $id, string $from, string $to) use (&$claims) {
			$claims[] = [$from, $to];
			return true;
		});
		$this->mail->method('sendToCandidate')->willThrowException(new ValidationException('SMTP down'));
		$this->stages->expects($this->never())->method('apply');
		$this->timeline->expects($this->never())->method('record');

		try {
			$this->service->send('hr', 5, 'Your offer', 'Welcome aboard');
			$this->fail('expected the mail failure');
		} catch (ValidationException $e) {
			$this->assertSame('SMTP down', $e->getMessage());
		}
		$this->assertSame([
			[Offer::STATUS_APPROVED, Offer::STATUS_SENT],
			[Offer::STATUS_SENT, Offer::STATUS_APPROVED],
		], $claims);
	}

	public function testAnAcceptanceThatCannotHireRollsBack(): void {
		$this->offerMapper->method('find')->willReturn($this->offer(5, Offer::STATUS_SENT));
		$this->stages->method('apply')->willThrowException(new \RuntimeException('deadlock'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->activity->expects($this->never())->method('publish');

		$this->expectException(\RuntimeException::class);
		$this->service->respond('hr', 5, Offer::STATUS_ACCEPTED);
	}

	public function testWithdrawWritesOfferAndTimelineInOneTransaction(): void {
		$this->offerMapper->method('find')->willReturn($this->offer(5, Offer::STATUS_DRAFT));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->offerMapper->expects($this->once())->method('update');
		$this->timeline->expects($this->once())->method('record')->with(42, TimelineEvent::TYPE_OFFER_WITHDRAWN, 'hr');
		$this->db->expects($this->once())->method('commit');

		$this->service->withdraw('hr', 5);
	}

	public function testOneFailingOfferDoesNotStopTheExpiryOfTheOthers(): void {
		$broken = $this->offer(1, Offer::STATUS_SENT, '2026-11-01');
		$overdue = $this->offer(2, Offer::STATUS_SENT, '2026-11-02');
		$expiring = $this->offer(3, Offer::STATUS_SENT, '2026-11-13');
		$this->offerMapper->method('findOpen')->willReturn([$broken, $overdue, $expiring]);
		$this->offerMapper->method('update')->willReturnCallback(static function (Offer $offer) {
			if ($offer->getId() === 1) {
				throw new \RuntimeException('row locked');
			}
			return $offer;
		});
		$this->db->expects($this->exactly(2))->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->notifications->expects($this->exactly(2))->method('notify');

		$result = $this->service->processExpiry();

		$this->assertSame(['expired' => 1, 'notified' => 1], $result);
		$this->assertSame(Offer::STATUS_EXPIRED, $overdue->getStatus());
		$this->assertTrue($expiring->getExpiryNotified());
	}
}
