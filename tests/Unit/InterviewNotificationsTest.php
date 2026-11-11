<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Notification\Notifier;
use OCA\Recruiting\Service\CandidateNameResolver;
use OCA\Recruiting\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class InterviewNotificationsTest extends TestCase {
	/**
	 * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
	 */
	public static function subjects(): array {
		return [
			'rescheduled by someone' => [NotificationService::SUBJECT_INTERVIEW_RESCHEDULED, ['actor' => 'boss'], '{actor} proposed new times for the interview "{interview}" with {candidate}'],
			'rescheduled' => [NotificationService::SUBJECT_INTERVIEW_RESCHEDULED, [], 'New times were proposed for the interview "{interview}" with {candidate}'],
			'feedback complete' => [NotificationService::SUBJECT_INTERVIEW_FEEDBACK_COMPLETE, ['actor' => 'bob'], 'All interviewers gave their feedback on "{interview}" with {candidate}'],
		];
	}

	/**
	 * @param array<string, string> $extra
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('subjects')]
	public function testInterviewSubjectsLinkToTheCandidate(string $subject, array $extra, string $text): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/recruiting/');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$urls->method('imagePath')->willReturn('/icon.svg');
		$names = $this->createMock(CandidateNameResolver::class);
		$names->method('displayName')->willReturn('Jane Doe');

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('recruiting');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn(array_merge(['candidateId' => '5', 'interviewTitle' => 'Tech talk'], $extra));
		$notification->expects($this->once())->method('setRichSubject')->with(
			$text,
			$this->callback(static fn (array $p): bool => $p['interview']['name'] === 'Tech talk' && $p['candidate']['name'] === 'Jane Doe'),
		)->willReturnSelf();
		$notification->expects($this->once())->method('setLink')->with('https://cloud.example/apps/recruiting/#/candidate/5')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();

		(new Notifier($factory, $urls, $this->createMock(IUserManager::class), $names))->prepare($notification, 'en');
	}
}
