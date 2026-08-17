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
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\ICommentsManager;
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
		$this->service = new RetentionService(
			$this->candidateMapper,
			$this->voteMapper,
			$this->eventMapper,
			$this->offerMapper,
			$this->documents,
			$this->createMock(NotificationService::class),
			$this->config,
			$this->createMock(ICommentsManager::class),
			$this->timeFactory,
			new NullLogger(),
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
		$this->assertSame(['Jane Doe'], $result['names']);
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
		$this->eventMapper->method('findForCandidate')->willReturn([$mailEvent, $stageEvent, $documentEvent]);

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
		// mail and document events are scrubbed, the stage history stays
		$this->assertSame([11, 13], $updatedEvents);
		$this->assertNull($mailEvent->getData());
		$this->assertNull($documentEvent->getData());
		$this->assertNotNull($stageEvent->getData());
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

		$service = new RetentionService(
			$this->candidateMapper,
			$this->voteMapper,
			$this->eventMapper,
			$offerMapper,
			$this->documents,
			$this->createMock(NotificationService::class),
			$this->config,
			$this->createMock(ICommentsManager::class),
			$this->timeFactory,
			new NullLogger(),
		);
		$service->anonymize($candidate);

		$this->assertNull($offer->getNotes());
		$this->assertSame('Designer', $offer->getJobTitle());
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
