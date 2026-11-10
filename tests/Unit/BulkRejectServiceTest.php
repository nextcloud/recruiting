<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Exception\NotPermittedException;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\BulkRejectService;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\PermissionService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Bulk rejection: one decision for many candidates, but permission, mail
 * rendering and failure handling per candidate.
 */
class BulkRejectServiceTest extends TestCase {
	private CandidateMapper&MockObject $candidateMapper;
	private OpeningMapper&MockObject $openingMapper;
	private CandidateService&MockObject $candidates;
	private MailService&MockObject $mail;
	private PermissionService&MockObject $permissions;
	private BulkRejectService $service;

	protected function setUp(): void {
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$this->openingMapper = $this->createMock(OpeningMapper::class);
		$this->candidates = $this->createMock(CandidateService::class);
		$this->mail = $this->createMock(MailService::class);
		$this->permissions = $this->createMock(PermissionService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		// Rendering stays observable: the context carries the candidate name
		$this->mail->method('contextFor')->willReturnCallback(
			static fn (Candidate $c, ?string $title) => ['candidate_name' => $c->getDisplayName(), 'opening_title' => (string)$title],
		);
		$this->mail->method('render')->willReturnCallback(
			static fn (string $text, array $context) => str_replace(['{{candidate_name}}', '{{opening_title}}'], [$context['candidate_name'], $context['opening_title']], $text),
		);
		$this->openingMapper->method('findTitle')->willReturn('Backend Engineer');

		$this->service = new BulkRejectService(
			$this->candidateMapper,
			$this->openingMapper,
			$this->candidates,
			$this->mail,
			$this->permissions,
			$l10n,
			new NullLogger(),
		);
	}

	private function candidate(int $id, string $email = '', int $openingId = 7): Candidate {
		$c = new Candidate();
		$c->setId($id);
		$c->setOpeningId($openingId);
		$c->setDisplayName('Candidate ' . $id);
		$c->setEmail($email);
		$c->setStage(Candidate::STAGE_SCREENING);
		return $c;
	}

	public function testRendersTheTemplatePerCandidate(): void {
		$this->candidateMapper->method('findByIds')->willReturn([
			$this->candidate(1, 'one@example.org'),
			$this->candidate(2, 'two@example.org'),
		]);
		$sent = [];
		$this->candidates->expects($this->exactly(2))->method('rejectCandidate')
			->willReturnCallback(function (string $uid, Candidate $c, string $reason, ?string $subject, ?string $body, bool $askPool) use (&$sent) {
				$sent[$c->getId()] = [$reason, $subject, $body, $askPool];
			});

		$result = $this->service->reject('hr', [1, 2], 'position_filled', true, 'Your application for {{opening_title}}', 'Dear {{candidate_name}}', true);

		$this->assertSame(['position_filled', 'Your application for Backend Engineer', 'Dear Candidate 1', true], $sent[1]);
		$this->assertSame(['position_filled', 'Your application for Backend Engineer', 'Dear Candidate 2', true], $sent[2]);
		$this->assertSame([
			['id' => 1, 'name' => 'Candidate 1', 'mailed' => true],
			['id' => 2, 'name' => 'Candidate 2', 'mailed' => true],
		], $result['rejected']);
		$this->assertSame([], $result['failed']);
	}

	public function testOneFailureNeverAbortsTheRest(): void {
		$this->candidateMapper->method('findByIds')->willReturn([
			$this->candidate(1, 'one@example.org'),
			$this->candidate(2, 'broken@example.org'),
			$this->candidate(3, 'three@example.org'),
		]);
		$this->permissions->method('canViewCandidate')->willReturn(true);
		$this->candidates->expects($this->exactly(3))->method('rejectCandidate')
			->willReturnCallback(function (string $uid, Candidate $c) {
				if ($c->getId() === 2) {
					throw new ValidationException('Sending the email failed.');
				}
			});

		$result = $this->service->reject('hr', [1, 2, 3], 'other', true, 'S', 'B', false);

		$this->assertSame([1, 3], array_column($result['rejected'], 'id'));
		$this->assertSame([['id' => 2, 'name' => 'Candidate 2', 'message' => 'Sending the email failed.']], $result['failed']);
	}

	public function testUnexpectedErrorsAreCollectedToo(): void {
		$this->candidateMapper->method('findByIds')->willReturn([$this->candidate(1), $this->candidate(2)]);
		$this->permissions->method('canViewCandidate')->willReturn(true);
		$this->candidates->method('rejectCandidate')->willReturnCallback(function (string $uid, Candidate $c) {
			if ($c->getId() === 1) {
				throw new \RuntimeException('database gone');
			}
		});

		$result = $this->service->reject('hr', [1, 2], 'other', false, '', '', false);

		$this->assertSame([2], array_column($result['rejected'], 'id'));
		$this->assertSame(1, $result['failed'][0]['id']);
		$this->assertSame('An unexpected error occurred.', $result['failed'][0]['message']);
	}

	public function testPermissionIsCheckedPerCandidateBeforeActing(): void {
		$this->candidateMapper->method('findByIds')->willReturn([
			$this->candidate(1, 'one@example.org', 7),
			$this->candidate(2, 'two@example.org', 8),
		]);
		$this->permissions->method('assertCanManageCandidate')->willReturnCallback(function (string $uid, Candidate $c) {
			if ($c->getOpeningId() === 8) {
				throw new NotPermittedException('No management access to this candidate');
			}
		});
		$this->permissions->method('canViewCandidate')->willReturnCallback(static fn (string $uid, Candidate $c) => $c->getOpeningId() !== 8);
		$this->candidates->expects($this->once())->method('rejectCandidate')
			->with('manager', $this->callback(static fn (Candidate $c) => $c->getId() === 1));
		// No mail is even rendered for the candidate without access
		$this->mail->expects($this->once())->method('contextFor');

		$result = $this->service->reject('manager', [1, 2], 'other', true, 'S', 'B', false);

		$this->assertSame([1], array_column($result['rejected'], 'id'));
		// The name of a candidate the user may not see is not revealed
		$this->assertSame([['id' => 2, 'name' => '', 'message' => 'No management access to this candidate']], $result['failed']);
	}

	public function testUnknownIdsAreReportedAsFailures(): void {
		$this->candidateMapper->method('findByIds')->willReturn([$this->candidate(1)]);

		$result = $this->service->reject('hr', [1, 99], 'other', false, '', '', false);

		$this->assertSame([1], array_column($result['rejected'], 'id'));
		$this->assertSame([['id' => 99, 'name' => '', 'message' => 'Not found']], $result['failed']);
	}

	public function testCandidatesWithoutAddressAreRejectedWithoutMail(): void {
		$this->candidateMapper->method('findByIds')->willReturn([$this->candidate(1, '')]);
		$this->mail->expects($this->never())->method('render');
		$this->candidates->expects($this->once())->method('rejectCandidate')
			->with('hr', $this->anything(), 'other', null, null, false);

		$result = $this->service->reject('hr', [1], 'other', true, 'S', 'B', true);

		$this->assertSame([['id' => 1, 'name' => 'Candidate 1', 'mailed' => false]], $result['rejected']);
	}

	public function testWithoutMailNothingIsRendered(): void {
		$this->candidateMapper->method('findByIds')->willReturn([$this->candidate(1, 'one@example.org')]);
		$this->mail->expects($this->never())->method('render');
		$this->candidates->expects($this->once())->method('rejectCandidate')
			->with('hr', $this->anything(), 'not_qualified', null, null, false);

		$result = $this->service->reject('hr', ['1', '1'], 'not_qualified', false, '', '', true);

		$this->assertSame([['id' => 1, 'name' => 'Candidate 1', 'mailed' => false]], $result['rejected']);
	}

	/**
	 * @return array<string, array{0: list<mixed>, 1: string, 2: bool, 3: string}>
	 */
	public static function invalidRequests(): array {
		return [
			'no ids' => [[], 'other', false, ''],
			'only garbage ids' => [['x', 0, -3], 'other', false, ''],
			'too many' => [range(1, BulkRejectService::MAX_PER_REQUEST + 1), 'other', false, ''],
			'unknown reason' => [[1], 'because', false, ''],
			'mail without text' => [[1], 'other', true, '  '],
		];
	}

	/**
	 * @param list<mixed> $ids
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('invalidRequests')]
	public function testInvalidRequestsChangeNothing(array $ids, string $reason, bool $sendMail, string $subject): void {
		$this->candidateMapper->expects($this->never())->method('findByIds');
		$this->candidates->expects($this->never())->method('rejectCandidate');

		$this->expectException(ValidationException::class);
		$this->service->reject('hr', $ids, $reason, $sendMail, $subject, 'Body', false);
	}
}
