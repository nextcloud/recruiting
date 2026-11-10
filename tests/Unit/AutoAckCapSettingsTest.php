<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Controller\SettingsController;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Notification\Notifier;
use OCA\Recruiting\Service\CandidateNameResolver;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\IngestionService;
use OCA\Recruiting\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Storage, admin setting and notification of the daily auto-ack cap.
 */
class AutoAckCapSettingsTest extends TestCase {
	public function testTheCapDefaultsTo100AndIsClamped(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default) => $default,
		);
		$stored = [];
		$appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value) use (&$stored): bool {
			$stored[] = $value;
			return true;
		});
		$config = new ConfigService($appConfig, $this->createMock(ICrypto::class));

		$this->assertSame(100, $config->getAutoAckDailyCap());
		$config->setAutoAckDailyCap(-5);
		$config->setAutoAckDailyCap(250);
		$config->setAutoAckDailyCap(10_000_000);
		$this->assertSame([0, 250, 100000], $stored);
	}

	public function testTheCounterRoundTripsThroughALazyKey(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$values = [];
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value, bool $lazy = false) use (&$values): bool {
			$this->assertTrue($lazy);
			$values[$key] = $value;
			return true;
		});
		$appConfig->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = '', bool $lazy = false) use (&$values): string {
			$this->assertTrue($lazy);
			return $values[$key] ?? $default;
		});
		$config = new ConfigService($appConfig, $this->createMock(ICrypto::class));

		$this->assertSame(['date' => '', 'count' => 0, 'notified' => false], $config->getAutoAckCounter(7));
		$config->setAutoAckCounter(7, ['date' => '2026-11-10', 'count' => 3, 'notified' => true]);
		$this->assertSame(['date' => '2026-11-10', 'count' => 3, 'notified' => true], $config->getAutoAckCounter(7));
		$this->assertArrayHasKey('auto_ack_count_7', $values);
	}

	public function testAdminsCanReadAndChangeTheCap(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['autoAckDailyCap' => '25']);
		$config = $this->createMock(ConfigService::class);
		$config->method('getImapConfig')->willReturn(['host' => '', 'port' => 993, 'security' => 'ssl', 'user' => '', 'password' => '']);
		$config->method('getAutoAckDailyCap')->willReturn(25);
		$config->expects($this->once())->method('setAutoAckDailyCap')->with(25);
		$controller = new SettingsController('recruiting', $request, $config, $this->createMock(IngestionService::class), $this->createMock(IGroupManager::class), $this->createMock(IL10N::class));

		$response = $controller->update();

		$this->assertSame(25, $response->getData()['autoAckDailyCap']);
	}

	public function testRecruitersGetOneNotificationEach(): void {
		$manager = $this->createMock(INotificationManager::class);
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		$manager->method('createNotification')->willReturn($notification);
		$notification->expects($this->exactly(2))->method('setObject')->with('opening', '7');
		$notification->expects($this->exactly(2))->method('setSubject')->with(
			NotificationService::SUBJECT_AUTO_ACK_CAP_REACHED,
			['openingId' => '7', 'openingTitle' => 'Backend Engineer', 'cap' => '100'],
		);
		$manager->expects($this->exactly(2))->method('notify');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());

		$opening = new Opening();
		$opening->setId(7);
		$opening->setTitle('Backend Engineer');
		(new NotificationService($manager, $time))->notifyAutoAckCapReached(['hr1', 'hr2', 'hr1', ''], $opening, 100);
	}

	public function testTheNotificationLinksToTheOpening(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/recruiting/');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$urls->method('imagePath')->willReturn('/icon.svg');

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('recruiting');
		$notification->method('getSubject')->willReturn(NotificationService::SUBJECT_AUTO_ACK_CAP_REACHED);
		$notification->method('getSubjectParameters')->willReturn(['openingId' => '7', 'openingTitle' => 'Backend Engineer', 'cap' => '100']);
		$notification->expects($this->once())->method('setRichSubject')->with(
			$this->stringContains('{opening}'),
			$this->callback(static fn (array $p): bool => $p['opening']['name'] === 'Backend Engineer' && $p['cap']['name'] === '100'),
		)->willReturnSelf();
		$notification->expects($this->once())->method('setLink')->with('https://cloud.example/apps/recruiting/#/openings/7')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();

		(new Notifier($factory, $urls, $this->createMock(IUserManager::class), $this->createMock(CandidateNameResolver::class)))->prepare($notification, 'en');
	}
}
