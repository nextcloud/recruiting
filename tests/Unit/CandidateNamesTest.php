<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Activity\Provider;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Notification\Notifier;
use OCA\Recruiting\Service\CandidateNameResolver;
use OCA\Recruiting\Service\NotificationService;
use OCP\Activity\IEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * Activity entries and notifications show the candidate's current name,
 * never the one stored when they were published: deletion and
 * anonymization must reach entries that already exist.
 */
class CandidateNamesTest extends TestCase {
	private CandidateMapper $candidateMapper;
	private IFactory $l10nFactory;
	private IURLGenerator $urlGenerator;
	private IUserManager $userManager;
	/** @var array<int, Candidate> */
	private array $rows = [];

	protected function setUp(): void {
		$this->rows = [
			7 => $this->candidate(7, 'Ada Lovelace', null),
			8 => $this->candidate(8, 'Anonymized candidate', new \DateTime('2026-08-01')),
		];
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->candidateMapper->method('find')->willReturnCallback(function (int $id) {
			if (!isset($this->rows[$id])) {
				throw new DoesNotExistException('gone');
			}
			return $this->rows[$id];
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $params = []) => vsprintf($text, is_array($params) ? $params : [$params]),
		);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->l10nFactory->method('get')->willReturn($l10n);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
	}

	private function candidate(int $id, string $name, ?\DateTime $anonymizedAt): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		$candidate->setDisplayName($name);
		$candidate->setAnonymizedAt($anonymizedAt);
		return $candidate;
	}

	private function l10n(): IL10N {
		return $this->l10nFactory->get('recruiting');
	}

	public function testResolverShowsTheLiveName(): void {
		$resolver = new CandidateNameResolver($this->candidateMapper);
		$this->assertSame('Ada Lovelace', $resolver->displayName(7, $this->l10n()));
	}

	public function testResolverHidesAnonymizedAndDeletedCandidates(): void {
		$resolver = new CandidateNameResolver($this->candidateMapper);
		$this->assertSame('Anonymized candidate', $resolver->displayName(8, $this->l10n()));
		$this->assertSame('Deleted candidate', $resolver->displayName(99, $this->l10n()));
		$this->assertSame('Deleted candidate', $resolver->displayName(0, $this->l10n()));
	}

	public function testResolverLooksEachCandidateUpOnce(): void {
		$mapper = $this->createMock(CandidateMapper::class);
		$mapper->expects($this->exactly(2))->method('find')->willReturnCallback(function (int $id) {
			if ($id === 7) {
				return $this->rows[7];
			}
			throw new DoesNotExistException('gone');
		});
		$resolver = new CandidateNameResolver($mapper);
		foreach ([7, 7, 99, 99, 7] as $id) {
			$resolver->displayName($id, $this->l10n());
		}
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function parseActivity(string $subject, array $params, int $objectId): string {
		$parsed = '';
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('recruiting');
		$event->method('getSubject')->willReturn($subject);
		$event->method('getSubjectParameters')->willReturn($params);
		$event->method('getObjectType')->willReturn('candidate');
		$event->method('getObjectId')->willReturn($objectId);
		$event->method('getAuthor')->willReturn('');
		$event->method('setParsedSubject')->willReturnCallback(function (string $text) use ($event, &$parsed) {
			$parsed = $text;
			return $event;
		});
		$event->method('setIcon')->willReturnSelf();
		$event->method('setLink')->willReturnSelf();

		$provider = new Provider($this->l10nFactory, $this->urlGenerator, $this->userManager, new CandidateNameResolver($this->candidateMapper));
		$provider->parse('en', $event);
		return $parsed;
	}

	public function testActivityShowsTheLiveName(): void {
		$this->assertSame('New application from Ada Lovelace', $this->parseActivity('application_created', ['candidateId' => 7], 7));
	}

	/**
	 * Rows written by older versions still carry the name in their
	 * parameters; it is ignored.
	 */
	public function testLegacyActivityIgnoresTheStoredName(): void {
		$this->assertSame(
			'New application from Anonymized candidate',
			$this->parseActivity('application_created', ['candidate' => 'Jane Doe', 'candidateId' => 8], 8),
		);
		$this->assertSame(
			'The offer for Deleted candidate expired without a response',
			$this->parseActivity('offer_expired', ['candidate' => 'Jane Doe', 'candidateId' => 42], 42),
		);
	}

	public function testRenamedCandidateShowsTheCurrentName(): void {
		$this->assertSame(
			'New application from Ada Lovelace',
			$this->parseActivity('application_created', ['candidate' => 'A. Lovelace (typo)', 'candidateId' => 7], 7),
		);
	}

	/**
	 * @param array<string, string> $params
	 * @return array{0: string, 1: array<string, array<string, string>>}
	 */
	private function prepareNotification(string $subject, array $params): array {
		$rich = ['', []];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('recruiting');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('setRichSubject')->willReturnCallback(function (string $text, array $parameters) use ($notification, &$rich) {
			$rich = [$text, $parameters];
			return $notification;
		});
		$notification->method('setLink')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();

		$notifier = new Notifier($this->l10nFactory, $this->urlGenerator, $this->userManager, new CandidateNameResolver($this->candidateMapper));
		$notifier->prepare($notification, 'en');
		return $rich;
	}

	public function testNotificationShowsTheLiveName(): void {
		[, $parameters] = $this->prepareNotification(NotificationService::SUBJECT_NEW_APPLICATION, ['candidateId' => '7']);
		$this->assertSame('Ada Lovelace', $parameters['candidate']['name']);
	}

	public function testLegacyNotificationIgnoresTheStoredName(): void {
		[, $parameters] = $this->prepareNotification(NotificationService::SUBJECT_CANDIDATE_REPLIED, ['candidateId' => '8', 'candidateName' => 'Jane Doe']);
		$this->assertSame('Anonymized candidate', $parameters['candidate']['name']);

		[, $parameters] = $this->prepareNotification(NotificationService::SUBJECT_CANDIDATE_REPLIED, ['candidateId' => '42', 'candidateName' => 'Jane Doe']);
		$this->assertSame('Deleted candidate', $parameters['candidate']['name']);
	}

	public function testNotificationsAreStoredWithoutTheName(): void {
		$stored = [];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params) use ($notification, &$stored) {
			$stored = $params;
			return $notification;
		});
		$manager = $this->createMock(\OCP\Notification\IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$time = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());

		(new NotificationService($manager, $time))->notify(['boss'], NotificationService::SUBJECT_NEW_APPLICATION, $this->rows[7], null);
		$this->assertSame('7', $stored['candidateId']);
		$this->assertNotContains('Ada Lovelace', $stored);
	}
}
