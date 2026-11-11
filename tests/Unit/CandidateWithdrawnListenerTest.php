<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Event\CandidateWithdrawnEvent;
use OCA\Recruiting\Listener\CandidateWithdrawnListener;
use OCA\Recruiting\Service\InterviewService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

class CandidateWithdrawnListenerTest extends TestCase {
	public function testOpenInterviewsAreCancelled(): void {
		$candidate = new Candidate();
		$candidate->setId(5);
		$interviews = $this->createMock(InterviewService::class);
		$interviews->expects($this->once())->method('cancelOpenFor')->with($candidate, null);
		(new CandidateWithdrawnListener($interviews))->handle(new CandidateWithdrawnEvent($candidate, null));
	}

	public function testOtherEventsAreIgnored(): void {
		$interviews = $this->createMock(InterviewService::class);
		$interviews->expects($this->never())->method('cancelOpenFor');
		(new CandidateWithdrawnListener($interviews))->handle(new Event());
	}
}
