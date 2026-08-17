<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Assignment;
use OCA\Recruiting\Db\AssignmentMapper;
use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\TeamMember;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Service\ActivityPublisher;
use OCA\Recruiting\Service\PermissionService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The activity feed is a published surface and must obey the same rules as
 * the candidate view (spec §10.4).
 */
class ActivityPublisherTest extends TestCase {
	private PermissionService $permissions;
	private ActivityPublisher $publisher;
	/** @var string[] */
	private array $notified = [];
	/** @var array<string, mixed> */
	private array $lastParameters = [];

	protected function setUp(): void {
		$this->notified = [];
		$this->lastParameters = [];

		$teamMapper = $this->createMock(TeamMemberMapper::class);
		$teamMapper->method('findForOpening')->willReturn([
			$this->member('boss', TeamMember::ROLE_MANAGER),
			$this->member('dev', TeamMember::ROLE_INTERVIEWER),
			$this->member('watcher', TeamMember::ROLE_OBSERVER),
		]);
		$assignmentMapper = $this->createMock(AssignmentMapper::class);
		$assignment = new Assignment();
		$assignment->setUid('dev');
		$assignmentMapper->method('findForCandidate')->willReturn([$assignment]);

		$this->permissions = $this->createMock(PermissionService::class);

		$event = $this->createMock(IEvent::class);
		foreach (['setApp', 'setType', 'setAuthor', 'setObject'] as $setter) {
			$event->method($setter)->willReturnSelf();
		}
		$event->method('setSubject')->willReturnCallback(function (string $subject, array $params) use ($event) {
			$this->lastParameters = $params;
			return $event;
		});
		$event->method('setAffectedUser')->willReturnCallback(function (string $uid) use ($event) {
			$this->notified[] = $uid;
			return $event;
		});
		$activityManager = $this->createMock(IActivityManager::class);
		$activityManager->method('generateEvent')->willReturn($event);

		$this->publisher = new ActivityPublisher(
			$activityManager,
			$teamMapper,
			$assignmentMapper,
			$this->permissions,
			new NullLogger(),
		);
	}

	private function member(string $uid, string $role): TeamMember {
		$member = new TeamMember();
		$member->setUid($uid);
		$member->setRole($role);
		return $member;
	}

	private function candidate(): Candidate {
		$candidate = new Candidate();
		$candidate->setId(7);
		$candidate->setOpeningId(3);
		$candidate->setDisplayName('Ada Lovelace');
		return $candidate;
	}

	public function testOfferActivityOnlyReachesPeopleWhoMaySeeOffers(): void {
		$this->permissions->method('canSeeOffers')
			->willReturnCallback(static fn (string $uid) => $uid === 'boss');
		$this->permissions->method('canViewCandidate')->willReturn(true);

		$this->publisher->publish($this->candidate(), 'offer_sent', 'boss');
		$this->assertSame(['boss'], $this->notified);
	}

	public function testOrdinaryActivityReachesEveryoneWhoMaySeeTheCandidate(): void {
		$this->permissions->method('canViewCandidate')
			->willReturnCallback(static fn (string $uid) => $uid !== 'watcher');
		$this->permissions->method('canSeeOffers')->willReturn(false);

		$this->publisher->publish($this->candidate(), 'stage_changed', 'boss');
		sort($this->notified);
		$this->assertSame(['boss', 'dev'], $this->notified);
	}

	/**
	 * An interviewer not assigned to this candidate must not learn about it
	 * through the feed either.
	 */
	public function testUnscopedInterviewerIsExcluded(): void {
		$this->permissions->method('canViewCandidate')
			->willReturnCallback(static fn (string $uid) => $uid === 'boss');
		$this->permissions->method('canSeeOffers')->willReturn(false);

		$this->publisher->publish($this->candidate(), 'application_created', null);
		$this->assertSame(['boss'], $this->notified);
	}

	public function testMailSubjectNeverTravelsWithTheEvent(): void {
		$this->permissions->method('canViewCandidate')->willReturn(true);
		$this->permissions->method('canSeeOffers')->willReturn(true);

		$this->publisher->publish($this->candidate(), 'mail_sent', 'boss', [
			'subject' => 'Your offer for the Senior Engineer position',
		]);
		$this->assertArrayNotHasKey('subject', $this->lastParameters);
		$this->assertSame('Ada Lovelace', $this->lastParameters['candidate']);
	}
}
