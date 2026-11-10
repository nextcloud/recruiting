<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewAttendee;
use OCA\Recruiting\Db\InterviewAttendeeMapper;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Service\CalendarService;
use OCA\Recruiting\Service\InterviewService;
use OCA\Recruiting\Service\TalkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Public interview links expire, and deletion/anonymization removes the
 * candidate from calendars, Talk and the public page.
 */
class InterviewPrivacyTest extends TestCase {
	private const NOW = '2026-08-16T12:00:00+00:00';

	/** @var array<class-string, MockObject> */
	private array $mocks = [];
	private Candidate $candidate;

	protected function setUp(): void {
		$this->mocks = [];
		$this->candidate = new Candidate();
		$this->candidate->setId(5);
		$this->candidate->setOpeningId(3);
		$this->candidate->setDisplayName('Jane Doe');
		$this->candidate->setStage(Candidate::STAGE_INTERVIEW);

		$time = $this->mock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn () => new \DateTime(self::NOW));
		$l10n = $this->mock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $params = []) => vsprintf($text, is_array($params) ? $params : [$params]),
		);
		$this->mock(CandidateMapper::class)->method('find')->willReturnCallback(function (int $id) {
			if ($id !== 5) {
				throw new DoesNotExistException('gone');
			}
			return $this->candidate;
		});
		$this->mock(OpeningMapper::class)->method('findTitle')->willReturn('Backend Developer');
		$this->mock(ISecureRandom::class)->method('generate')->willReturn(str_repeat('n', 48));
		$organizer = $this->createMock(IUser::class);
		$organizer->method('getUID')->willReturn('boss');
		$this->mock(IUserManager::class)->method('get')->willReturn($organizer);
		$attendee = new InterviewAttendee();
		$attendee->setUid('dev');
		$this->mock(InterviewAttendeeMapper::class)->method('findForInterview')->willReturn([$attendee]);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T&MockObject
	 */
	private function mock(string $class): MockObject {
		return $this->mocks[$class] ??= $this->createMock($class);
	}

	private function service(): InterviewService {
		$args = [];
		foreach ((new \ReflectionClass(InterviewService::class))->getConstructor()->getParameters() as $parameter) {
			/** @var class-string $type */
			$type = (string)$parameter->getType();
			$args[] = $this->mock($type);
		}
		return new InterviewService(...$args);
	}

	private function interview(string $status, ?string $startAt = null, ?string $endAt = null): Interview {
		$interview = new Interview();
		$interview->setId(random_int(1, 100000));
		$interview->setCandidateId(5);
		$interview->setStatus($status);
		$interview->setPublicToken(str_repeat('o', 48));
		$interview->setCreatedBy('boss');
		$interview->setStartAt($startAt !== null ? new \DateTime($startAt) : null);
		$interview->setEndAt($endAt !== null ? new \DateTime($endAt) : null);
		return $interview;
	}

	private function lookup(Interview $interview): ?Interview {
		$this->mock(InterviewMapper::class)->method('findByToken')->willReturn($interview);
		return $this->service()->findByPublicToken($interview->getPublicToken());
	}

	public function testProposedAndUpcomingInterviewsKeepTheirLink(): void {
		$proposed = $this->interview(Interview::STATUS_PROPOSED);
		$this->assertSame($proposed, $this->lookup($proposed));
	}

	public function testConfirmedFutureInterviewKeepsItsLink(): void {
		$confirmed = $this->interview(Interview::STATUS_CONFIRMED, '2026-08-20T10:00:00+00:00', '2026-08-20T11:00:00+00:00');
		$this->assertSame($confirmed, $this->lookup($confirmed));
	}

	public function testLinkWorksForAWeekAfterTheInterview(): void {
		$recent = $this->interview(Interview::STATUS_CONFIRMED, '2026-08-10T10:00:00+00:00', '2026-08-10T11:00:00+00:00');
		$this->assertSame($recent, $this->lookup($recent));
	}

	public function testLinkExpiresSevenDaysAfterTheEnd(): void {
		$old = $this->interview(Interview::STATUS_CONFIRMED, '2026-08-09T10:00:00+00:00', '2026-08-09T11:00:00+00:00');
		$this->assertNull($this->lookup($old));
	}

	public function testCancelledInterviewHasNoLink(): void {
		$this->assertNull($this->lookup($this->interview(Interview::STATUS_CANCELLED)));
	}

	public function testUnknownTokenHasNoLink(): void {
		$this->mock(InterviewMapper::class)->method('findByToken')->willReturn(null);
		$this->assertNull($this->service()->findByPublicToken(str_repeat('x', 48)));
	}

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function closedCandidates(): array {
		return [
			'rejected' => [Candidate::STAGE_REJECTED, null],
			'withdrawn' => [Candidate::STAGE_WITHDRAWN, null],
			'anonymized' => [Candidate::STAGE_REJECTED, '2026-08-01T00:00:00+00:00'],
			'anonymized while active' => [Candidate::STAGE_SCREENING, '2026-08-01T00:00:00+00:00'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('closedCandidates')]
	public function testFinishedCandidatesLoseTheirLink(string $stage, ?string $anonymizedAt): void {
		$this->candidate->setStage($stage);
		$this->candidate->setAnonymizedAt($anonymizedAt !== null ? new \DateTime($anonymizedAt) : null);
		$confirmed = $this->interview(Interview::STATUS_CONFIRMED, '2026-08-20T10:00:00+00:00', '2026-08-20T11:00:00+00:00');
		$this->assertNull($this->lookup($confirmed));
	}

	public function testDeletedCandidateHasNoLink(): void {
		$interview = $this->interview(Interview::STATUS_PROPOSED);
		$interview->setCandidateId(99);
		$this->assertNull($this->lookup($interview));
	}

	public function testScrubCancelsUpcomingAndNeutralizesPastInterviews(): void {
		$upcoming = $this->interview(Interview::STATUS_CONFIRMED, '2026-08-20T10:00:00+00:00', '2026-08-20T11:00:00+00:00');
		$upcoming->setCalendarUid('up.ics');
		$upcoming->setTalkToken('talkup');
		$upcoming->setTalkLink('https://cloud/call/talkup');
		$past = $this->interview(Interview::STATUS_CONFIRMED, '2026-08-01T10:00:00+00:00', '2026-08-01T11:00:00+00:00');
		$past->setCalendarUid('past.ics');
		$past->setIsVideo(true);
		$past->setTalkToken('talkpast');
		$past->setTalkLink('https://cloud/call/talkpast');
		$cancelled = $this->interview(Interview::STATUS_CANCELLED);
		$this->mock(InterviewMapper::class)->method('findForCandidate')->willReturn([$upcoming, $past, $cancelled]);

		$calendar = $this->mock(CalendarService::class);
		$calendar->expects($this->once())->method('cancelEvent')
			->with($this->anything(), 'up.ics', ['dev'], true);
		$calendar->expects($this->once())->method('neutralizeEvent')
			->with($this->anything(), 'past.ics', ['dev'], 'Interview: Backend Developer', 'Opening: Backend Developer', null);
		$deletedRooms = [];
		$this->mock(TalkService::class)->method('deleteRoom')->willReturnCallback(static function (string $token) use (&$deletedRooms) {
			$deletedRooms[] = $token;
		});

		$this->service()->scrubForCandidate($this->candidate, revokeLinks: true);

		$this->assertSame(['talkup', 'talkpast'], $deletedRooms);
		$this->assertSame(Interview::STATUS_CANCELLED, $upcoming->getStatus());
		$this->assertSame(Interview::STATUS_CONFIRMED, $past->getStatus());
		$this->assertSame('', $past->getTalkLink());
		$this->assertSame('', $past->getTalkToken());
		foreach ([$upcoming, $past, $cancelled] as $interview) {
			$this->assertSame(str_repeat('n', 48), $interview->getPublicToken());
		}
	}

	public function testInPersonInterviewKeepsItsLocation(): void {
		$past = $this->interview(Interview::STATUS_DONE, '2026-08-01T10:00:00+00:00', '2026-08-01T11:00:00+00:00');
		$past->setCalendarUid('past.ics');
		$past->setLocation('Office Berlin');
		$this->mock(InterviewMapper::class)->method('findForCandidate')->willReturn([$past]);
		$this->mock(CalendarService::class)->expects($this->once())->method('neutralizeEvent')
			->with($this->anything(), 'past.ics', ['dev'], $this->anything(), $this->anything(), 'Office Berlin');

		$this->service()->scrubForCandidate($this->candidate, revokeLinks: false);
		$this->assertSame(str_repeat('o', 48), $past->getPublicToken());
	}
}
