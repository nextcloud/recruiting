<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Dashboard\TodayWidget;
use OCA\Recruiting\Service\TodayService;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TodayWidgetTest extends TestCase {
	private TodayService&MockObject $today;
	private IUserSession&MockObject $session;
	private TodayWidget $widget;

	protected function setUp(): void {
		$this->today = $this->createMock(TodayService::class);
		$this->session = $this->createMock(IUserSession::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud/apps/recruiting/');
		$urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDateTime')->willReturn('11/11/26, 2:00 PM');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []) => vsprintf($text, $p));
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $n) => str_replace('%n', (string)$n, $n === 1 ? $one : $many));
		$this->widget = new TodayWidget($this->today, $this->session, $urlGenerator, $formatter, $l10n);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function overview(array $overrides = []): array {
		return array_merge([
			'isRecruiter' => false,
			'openingCount' => 1,
			'votesOwed' => [],
			'votesOwedCount' => 0,
			'interviews' => [],
			'stuck' => [],
			'stuckCount' => 0,
			'approvals' => [],
			'triageCount' => null,
			'mailbox' => null,
		], $overrides);
	}

	public function testTheWidgetIsOnlyShownToPeopleWithARecruitingRole(): void {
		$this->session->method('getUser')->willReturnOnConsecutiveCalls(null, $this->user('bob'), $this->user('hr'));
		$this->today->method('hasRecruitingRole')->willReturnMap([['', false], ['bob', false], ['hr', true]]);

		$this->assertFalse($this->widget->isEnabled());
		$this->assertFalse($this->widget->isEnabled());
		$this->assertTrue($this->widget->isEnabled());
	}

	public function testItemsLinkToTheCandidateInPriorityOrder(): void {
		$candidate = ['id' => 5, 'displayName' => 'Ada', 'openingId' => 1, 'openingTitle' => 'Backend', 'stage' => 'screening'];
		$this->today->method('forUser')->with('hr')->willReturn($this->overview([
			'votesOwed' => [$candidate],
			'interviews' => [['id' => 3, 'title' => 'Tech talk', 'startAt' => '2026-11-11T14:00:00+00:00', 'candidate' => $candidate]],
			'approvals' => [['offerId' => 9, 'jobTitle' => 'Engineer', 'candidate' => $candidate]],
			'stuck' => [array_merge($candidate, ['attention' => ['no_votes']])],
			'triageCount' => 2,
			'mailbox' => ['enabled' => true, 'status' => ['error' => 'Login failed']],
		]));

		$items = $this->widget->getItemsV2('hr', null, 10)->getItems();

		$this->assertSame([
			'Approve the offer for Ada',
			'Tech talk with Ada',
			'Review Ada',
			'Ada',
			'The last mailbox check failed',
			'2 applications in the triage inbox',
		], array_map(static fn ($item) => $item->getTitle(), $items));
		$this->assertSame('https://cloud/apps/recruiting/#/candidate/5', $items[0]->getLink());
		$this->assertSame('11/11/26, 2:00 PM', $items[1]->getSubtitle());
		$this->assertSame('https://cloud/apps/recruiting/#/triage', $items[5]->getLink());
	}

	public function testTheLimitIsRespected(): void {
		$candidates = array_map(static fn (int $id) => ['id' => $id, 'displayName' => 'C' . $id, 'openingId' => 1, 'openingTitle' => 'B', 'stage' => 'screening'], range(1, 10));
		$this->today->method('forUser')->willReturn($this->overview(['votesOwed' => $candidates]));

		$this->assertCount(7, $this->widget->getItemsV2('hr')->getItems());
	}

	public function testAnEmptyDayHasAFriendlyMessage(): void {
		$this->today->method('forUser')->willReturn($this->overview());

		$items = $this->widget->getItemsV2('hr');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Nothing is waiting for you', $items->getEmptyContentMessage());
	}
}
