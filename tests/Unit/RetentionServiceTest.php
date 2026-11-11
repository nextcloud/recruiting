<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Db\VoteMapper;
use OCA\Recruiting\Service\ActivityPurgeService;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\ICommentsManager;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RetentionServiceTest extends TestCase {
	private CandidateMapper $candidateMapper;
	private VoteMapper $voteMapper;
	private TimelineEventMapper $eventMapper;
	private DocumentService $documents;
	private ConfigService $config;
	private OfferMapper $offerMapper;
	private ITimeFactory $timeFactory;
	private InterviewService $interviews;
	private ActivityPurgeService $activityPurge;
	private CandidateService $candidates;
	private IDBConnection&MockObject $db;
	private RetentionService $service;

	protected function setUp(): void {
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->voteMapper = $this->createMock(VoteMapper::class);
		$this->eventMapper = $this->createMock(TimelineEventMapper::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-08-16T12:00:00+00:00'));

		$this->offerMapper = $this->createMock(OfferMapper::class);
		$this->offerMapper->method('findForCandidate')->willReturn([]);
		$this->interviews = $this->createMock(InterviewService::class);
		$this->activityPurge = $this->createMock(ActivityPurgeService::class);
		$this->candidates = $this->createMock(CandidateService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = $this->makeService($this->offerMapper);
	}

	private function makeService(OfferMapper $offerMapper): RetentionService {
		return new RetentionService(
			$this->candidateMapper,
			$this->voteMapper,
			$this->eventMapper,
			$offerMapper,
			$this->documents,
			$this->createMock(NotificationService::class),
			$this->interviews,
			$this->activityPurge,
			$this->candidates,
			$this->config,
			$this->createMock(ICommentsManager::class),
			$this->timeFactory,
			new NullLogger(),
			$this->db,
		);
	}

	public function testDisabledRetentionFindsNothing(): void {
		$this->config->method('getRetentionDays')->willReturn(0);
		$this->candidateMapper->expects($this->never())->method('findRetentionEligible');
		$this->assertSame([], $this->service->findEligible());
	}

	public function testCutoffIsRetentionDaysAgo(): void {
		$this->config->method('getRetentionDays')->willReturn(180);
		$this->candidateMapper->expects($this->once())
			->method('findRetentionEligible')
			->with($this->callback(
				static fn (\DateTimeInterface $cutoff) => $cutoff->format('Y-m-d') === '2026-02-17',
			))
			->willReturn([]);
		$this->service->findEligible();
	}

	public function testDryRunTouchesNothing(): void {
		$this->config->method('getRetentionDays')->willReturn(30);
		$candidate = new Candidate();
		$candidate->setId(5);
		$candidate->setDisplayName('Jane Doe');
		$this->candidateMapper->method('findRetentionEligible')->willReturn([$candidate]);

		$this->documents->expects($this->never())->method('deleteAllFor');
		$this->candidateMapper->expects($this->never())->method('update');

		$result = $this->service->run(dryRun: true);
		$this->assertSame(1, $result['anonymized']);
		$this->assertSame(['Jane Doe'], $result['groups'][RetentionService::GROUP_ENDED]['names']);
	}

	public function testAnonymizeScrubsEverythingIdentifying(): void {
		$candidate = new Candidate();
		$candidate->setId(5);
		$candidate->setDisplayName('Jane Doe');
		$candidate->setEmail('jane@example.org');
		$candidate->setPhone('+49 123');
		$candidate->setAiSummary('Great candidate');
		$candidate->setDuplicateOf(3);

		$mailEvent = new TimelineEvent();
		$mailEvent->setId(11);
		$mailEvent->setCandidateId(5);
		$mailEvent->setType(TimelineEvent::TYPE_MAIL_RECEIVED);
		$mailEvent->setData(json_encode(['subject' => 'Application', 'body' => 'personal data']));
		$stageEvent = new TimelineEvent();
		$stageEvent->setId(12);
		$stageEvent->setCandidateId(5);
		$stageEvent->setType(TimelineEvent::TYPE_STAGE_CHANGED);
		$stageEvent->setData(json_encode(['from' => 'new', 'to' => 'rejected']));
		// The CV filename identifies the very person being anonymized
		$documentEvent = new TimelineEvent();
		$documentEvent->setId(13);
		$documentEvent->setCandidateId(5);
		$documentEvent->setType(TimelineEvent::TYPE_DOCUMENT_ADDED);
		$documentEvent->setData(json_encode(['name' => 'Jane_Doe_CV.pdf']));
		// A public web application: the cover letter is personal data too.
		$webEvent = new TimelineEvent();
		$webEvent->setId(14);
		$webEvent->setCandidateId(5);
		$webEvent->setType(TimelineEvent::TYPE_WEB_APPLICATION);
		$webEvent->setData(json_encode(['subject' => 'Application', 'body' => 'I am Jane, I worked at ...']));
		$this->eventMapper->method('findForCandidate')->willReturn([$mailEvent, $stageEvent, $documentEvent, $webEvent]);

		$this->documents->expects($this->once())->method('deleteAllFor')->with(5);
		$this->voteMapper->expects($this->once())->method('stripCommentsForCandidate')->with(5);
		$updatedEvents = [];
		$this->eventMapper->method('update')->willReturnCallback(function (TimelineEvent $event) use (&$updatedEvents) {
			$updatedEvents[] = $event->getId();
			return $event;
		});

		$this->service->anonymize($candidate);

		$this->assertSame(RetentionService::ANONYMIZED_NAME, $candidate->getDisplayName());
		$this->assertSame('', $candidate->getEmail());
		$this->assertSame('', $candidate->getPhone());
		$this->assertNull($candidate->getAiSummary());
		$this->assertNull($candidate->getDuplicateOf());
		$this->assertNotNull($candidate->getAnonymizedAt());
		$this->assertFalse($candidate->getPoolMember());
		$this->assertNull($candidate->getPoolConsentToken());
		// mail, document and web-application events are scrubbed, stage stays
		$this->assertSame([11, 13, 14], $updatedEvents);
		$this->assertNull($mailEvent->getData());
		$this->assertNull($documentEvent->getData());
		$this->assertNull($webEvent->getData());
		$this->assertNotNull($stageEvent->getData());
	}

	public function testAnonymizeClearsThePortalTokenAndCandidateMessages(): void {
		$candidate = new Candidate();
		$candidate->setId(5);
		$candidate->setPortalToken(str_repeat('p', 48));
		$events = [];
		foreach ([TimelineEvent::TYPE_REFERRAL_NOTE, TimelineEvent::TYPE_INTERVIEW_SLOTS_DECLINED, TimelineEvent::TYPE_INTERVIEW_RESCHEDULE_REQUESTED] as $i => $type) {
			$event = new TimelineEvent();
			$event->setId(20 + $i);
			$event->setType($type);
			$event->setData(json_encode(['note' => 'free text about Jane', 'message' => 'Jane here']));
			$events[] = $event;
		}
		$this->eventMapper->method('findForCandidate')->willReturn($events);

		$this->service->anonymize($candidate);

		$this->assertNull($candidate->getPortalToken());
		foreach ($events as $event) {
			$this->assertNull($event->getData(), $event->getType());
		}
	}

	public function testOfferNotesAreScrubbedButTermsStay(): void {
		$candidate = $this->candidate(5);
		$offer = new \OCA\Recruiting\Db\Offer();
		$offer->setId(3);
		$offer->setCandidateId(5);
		$offer->setJobTitle('Designer');
		$offer->setNotes('Jane wants to relocate from Berlin');

		$offerMapper = $this->createMock(OfferMapper::class);
		$offerMapper->method('findForCandidate')->willReturn([$offer]);
		$offerMapper->expects($this->once())->method('update');
		$this->eventMapper->method('findForCandidate')->willReturn([]);

		$this->makeService($offerMapper)->anonymize($candidate);

		$this->assertNull($offer->getNotes());
		$this->assertSame('Designer', $offer->getJobTitle());
	}

	public function testAnonymizePurgesActivityAndScrubsInterviews(): void {
		$candidate = $this->candidate(5);
		$this->eventMapper->method('findForCandidate')->willReturn([]);

		$this->activityPurge->expects($this->once())->method('purgeForCandidate')->with(5);
		$this->interviews->expects($this->once())->method('scrubForCandidate')->with($candidate, true);

		$this->service->anonymize($candidate);
	}

	public function testAnonymizeScrubsCalendarAndFilesBeforeTheRowsChangeInOneTransaction(): void {
		$candidate = $this->candidate(5);
		$this->eventMapper->method('findForCandidate')->willReturn([]);
		$order = [];
		$this->interviews->method('scrubForCandidate')->willReturnCallback(function () use (&$order): void {
			$order[] = 'calendar';
		});
		$this->documents->method('deleteAllFor')->willReturnCallback(function () use (&$order): void {
			$order[] = 'files';
		});
		$this->db->method('beginTransaction')->willReturnCallback(function () use (&$order): void {
			$order[] = 'begin';
		});
		$this->candidateMapper->method('update')->willReturnCallback(function (Candidate $c) use (&$order) {
			$order[] = 'candidate';
			return $c;
		});
		$this->db->method('commit')->willReturnCallback(function () use (&$order): void {
			$order[] = 'commit';
		});
		$this->activityPurge->method('purgeForCandidate')->willReturnCallback(function () use (&$order): void {
			$order[] = 'activity';
		});

		$this->service->anonymize($candidate);

		$this->assertSame(['calendar', 'files', 'begin', 'candidate', 'commit', 'activity'], $order);
	}

	public function testAFailedFileDeleteLeavesTheCandidateForTheNextRun(): void {
		$candidate = $this->candidate(5);
		$this->documents->method('deleteAllFor')->willThrowException(new \RuntimeException('storage down'));
		$this->db->expects($this->never())->method('beginTransaction');
		$this->candidateMapper->expects($this->never())->method('update');

		$this->expectException(\RuntimeException::class);
		$this->service->anonymize($candidate);
	}

	public function testAFailingRowUpdateRollsTheAnonymizationBack(): void {
		$candidate = $this->candidate(5);
		$this->eventMapper->method('findForCandidate')->willReturn([]);
		$this->candidateMapper->method('update')->willThrowException(new \RuntimeException('deadlock'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->eventMapper->expects($this->never())->method('insert');
		$this->activityPurge->expects($this->never())->method('purgeForCandidate');

		$this->expectException(\RuntimeException::class);
		$this->service->anonymize($candidate);
	}

	public function testClosedOpeningRuleUsesTheRetentionPeriod(): void {
		$this->config->method('getRetentionDays')->willReturn(30);
		$this->candidateMapper->expects($this->once())
			->method('findLeftInClosedOpenings')
			->with($this->callback(
				static fn (\DateTimeInterface $cutoff) => $cutoff->format('Y-m-d') === '2026-07-17',
			))
			->willReturn([]);
		$this->service->findLeftInClosedOpenings();
	}

	public function testClosedOpeningRuleIsOffWithRetention(): void {
		$this->config->method('getRetentionDays')->willReturn(0);
		$this->candidateMapper->expects($this->never())->method('findLeftInClosedOpenings');
		$this->assertSame([], $this->service->findLeftInClosedOpenings());
	}

	public function testTriageRuleUsesItsOwnPeriod(): void {
		$this->config->method('getRetentionDays')->willReturn(0);
		$this->config->method('getTriageRetentionDays')->willReturn(90);
		$this->candidateMapper->expects($this->once())
			->method('findTriageCreatedBefore')
			->with($this->callback(
				static fn (\DateTimeInterface $cutoff) => $cutoff->format('Y-m-d') === '2026-05-18',
			))
			->willReturn([]);
		$this->service->findExpiredTriage();
	}

	public function testTriageRuleCanBeDisabled(): void {
		$this->config->method('getTriageRetentionDays')->willReturn(0);
		$this->candidateMapper->expects($this->never())->method('findTriageCreatedBefore');
		$this->assertSame([], $this->service->findExpiredTriage());
	}

	public function testDryRunListsEveryGroupWithCountAndReason(): void {
		$this->config->method('getRetentionDays')->willReturn(180);
		$this->config->method('getTriageRetentionDays')->willReturn(90);
		$this->config->method('getPoolRetentionMonths')->willReturn(12);
		$this->candidateMapper->method('findTriageCreatedBefore')->willReturn([$this->named(1, 'Mail Delivery System')]);
		$this->candidateMapper->method('findRetentionEligible')->willReturn([$this->named(2, 'Rita Rejected')]);
		$this->candidateMapper->method('findLeftInClosedOpenings')->willReturn([$this->named(3, 'Carl Closed'), $this->named(4, 'Nina New')]);
		$this->candidateMapper->method('findExpiredPoolMembers')->willReturn([]);

		$this->candidates->expects($this->never())->method('purge');
		$this->candidateMapper->expects($this->never())->method('update');

		$result = $this->service->run(dryRun: true);

		$this->assertSame(3, $result['anonymized']);
		$this->assertSame(1, $result['deleted']);
		$groups = $result['groups'];
		$this->assertSame(
			[RetentionService::GROUP_TRIAGE, RetentionService::GROUP_ENDED, RetentionService::GROUP_CLOSED_OPENING, RetentionService::GROUP_POOL_EXPIRED],
			array_keys($groups),
		);
		$this->assertSame(RetentionService::ACTION_DELETE, $groups[RetentionService::GROUP_TRIAGE]['action']);
		$this->assertSame(['Mail Delivery System'], $groups[RetentionService::GROUP_TRIAGE]['names']);
		$this->assertStringContainsString('90 days', $groups[RetentionService::GROUP_TRIAGE]['reason']);
		$this->assertSame(RetentionService::ACTION_ANONYMIZE, $groups[RetentionService::GROUP_CLOSED_OPENING]['action']);
		$this->assertSame(['Carl Closed', 'Nina New'], $groups[RetentionService::GROUP_CLOSED_OPENING]['names']);
		$this->assertStringContainsString('opening closed more than 180 days ago', $groups[RetentionService::GROUP_CLOSED_OPENING]['reason']);
		$this->assertSame([], $groups[RetentionService::GROUP_POOL_EXPIRED]['names']);
		$this->assertTrue($groups[RetentionService::GROUP_POOL_EXPIRED]['enabled']);
	}

	public function testDisabledGroupsAreMarked(): void {
		$this->config->method('getRetentionDays')->willReturn(0);
		$this->config->method('getTriageRetentionDays')->willReturn(0);
		$this->config->method('getPoolRetentionMonths')->willReturn(12);
		$this->candidateMapper->method('findExpiredPoolMembers')->willReturn([]);

		$groups = $this->service->run(dryRun: true)['groups'];
		$this->assertFalse($groups[RetentionService::GROUP_TRIAGE]['enabled']);
		$this->assertFalse($groups[RetentionService::GROUP_ENDED]['enabled']);
		$this->assertFalse($groups[RetentionService::GROUP_CLOSED_OPENING]['enabled']);
	}

	/**
	 * Triage candidates are deleted through the regular deletion path; the
	 * other groups are anonymized. A rejected triage candidate is in both
	 * lists and is only deleted.
	 */
	public function testRunDeletesTriageAndAnonymizesTheRest(): void {
		$this->config->method('getRetentionDays')->willReturn(180);
		$this->config->method('getTriageRetentionDays')->willReturn(90);
		$this->config->method('getPoolRetentionMonths')->willReturn(12);
		$spam = $this->named(1, 'Spam');
		$closed = $this->named(3, 'Carl Closed');
		$this->candidateMapper->method('findTriageCreatedBefore')->willReturn([$spam]);
		$this->candidateMapper->method('findRetentionEligible')->willReturn([$spam]);
		$this->candidateMapper->method('findLeftInClosedOpenings')->willReturn([$closed]);
		$this->candidateMapper->method('findExpiredPoolMembers')->willReturn([]);
		$this->eventMapper->method('findForCandidate')->willReturn([]);

		$this->candidates->expects($this->once())->method('purge')->with($spam);
		$anonymized = [];
		$this->candidateMapper->method('update')->willReturnCallback(function (Candidate $c) use (&$anonymized) {
			$anonymized[] = $c->getId();
			return $c;
		});

		$result = $this->service->run();
		$this->assertSame(1, $result['deleted']);
		$this->assertSame(1, $result['anonymized']);
		$this->assertSame([3], $anonymized);
		$this->assertSame([], $result['groups'][RetentionService::GROUP_ENDED]['names']);
		$this->assertSame(RetentionService::ANONYMIZED_NAME, $closed->getDisplayName());
	}

	private function named(int $id, string $name): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setDisplayName($name);
		return $candidate;
	}

	private function candidate(int $id): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setDisplayName('Jane Doe');
		$candidate->setPoolMember(true);
		$candidate->setPoolConsentToken(str_repeat('t', 48));
		return $candidate;
	}
}
