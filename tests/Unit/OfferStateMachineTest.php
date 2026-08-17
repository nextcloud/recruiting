<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Offer;
use PHPUnit\Framework\TestCase;

class OfferStateMachineTest extends TestCase {
	private function offer(string $status): Offer {
		$offer = new Offer();
		$offer->setStatus($status);
		return $offer;
	}

	public function testHappyPath(): void {
		$this->assertTrue($this->offer(Offer::STATUS_DRAFT)->canTransitionTo(Offer::STATUS_PENDING_APPROVAL));
		$this->assertTrue($this->offer(Offer::STATUS_PENDING_APPROVAL)->canTransitionTo(Offer::STATUS_APPROVED));
		$this->assertTrue($this->offer(Offer::STATUS_APPROVED)->canTransitionTo(Offer::STATUS_SENT));
		$this->assertTrue($this->offer(Offer::STATUS_SENT)->canTransitionTo(Offer::STATUS_ACCEPTED));
	}

	public function testApprovalCanBeDeclinedBackToDraft(): void {
		$this->assertTrue($this->offer(Offer::STATUS_PENDING_APPROVAL)->canTransitionTo(Offer::STATUS_DRAFT));
	}

	public function testNegotiationFlow(): void {
		$this->assertTrue($this->offer(Offer::STATUS_SENT)->canTransitionTo(Offer::STATUS_NEGOTIATING));
		$this->assertTrue($this->offer(Offer::STATUS_NEGOTIATING)->canTransitionTo(Offer::STATUS_ACCEPTED));
		$this->assertTrue($this->offer(Offer::STATUS_NEGOTIATING)->canTransitionTo(Offer::STATUS_DECLINED));
		// but not back to plain "sent"
		$this->assertFalse($this->offer(Offer::STATUS_NEGOTIATING)->canTransitionTo(Offer::STATUS_SENT));
	}

	public function testNoShortcuts(): void {
		// approval cannot be skipped
		$this->assertFalse($this->offer(Offer::STATUS_DRAFT)->canTransitionTo(Offer::STATUS_APPROVED));
		$this->assertFalse($this->offer(Offer::STATUS_DRAFT)->canTransitionTo(Offer::STATUS_SENT));
		$this->assertFalse($this->offer(Offer::STATUS_DRAFT)->canTransitionTo(Offer::STATUS_ACCEPTED));
		// an unsent offer cannot be answered
		$this->assertFalse($this->offer(Offer::STATUS_APPROVED)->canTransitionTo(Offer::STATUS_ACCEPTED));
		$this->assertFalse($this->offer(Offer::STATUS_PENDING_APPROVAL)->canTransitionTo(Offer::STATUS_SENT));
	}

	public function testTerminalStatesAreFinal(): void {
		foreach (Offer::TERMINAL_STATES as $terminal) {
			$offer = $this->offer($terminal);
			foreach (array_keys(Offer::TRANSITIONS) as $target) {
				$this->assertFalse($offer->canTransitionTo($target), "$terminal must not transition to $target");
			}
			$this->assertTrue($offer->isTerminal());
		}
	}

	public function testExpiryOnlyFromOpenStates(): void {
		$this->assertTrue($this->offer(Offer::STATUS_SENT)->canTransitionTo(Offer::STATUS_EXPIRED));
		$this->assertTrue($this->offer(Offer::STATUS_NEGOTIATING)->canTransitionTo(Offer::STATUS_EXPIRED));
		$this->assertFalse($this->offer(Offer::STATUS_DRAFT)->canTransitionTo(Offer::STATUS_EXPIRED));
		$this->assertFalse($this->offer(Offer::STATUS_APPROVED)->canTransitionTo(Offer::STATUS_EXPIRED));
	}

	public function testWithdrawableWheneverNotTerminal(): void {
		foreach ([Offer::STATUS_DRAFT, Offer::STATUS_PENDING_APPROVAL, Offer::STATUS_APPROVED, Offer::STATUS_SENT, Offer::STATUS_NEGOTIATING] as $status) {
			$this->assertTrue($this->offer($status)->canTransitionTo(Offer::STATUS_WITHDRAWN), "$status must be withdrawable");
		}
	}

	public function testEveryTransitionTargetIsAKnownState(): void {
		foreach (Offer::TRANSITIONS as $from => $targets) {
			foreach ($targets as $target) {
				$this->assertArrayHasKey($target, Offer::TRANSITIONS, "$from → $target points to an unknown state");
			}
		}
	}
}
