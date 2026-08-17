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
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Service\AiService;
use OCP\IL10N;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AiServiceTest extends TestCase {
	private CandidateMapper $candidateMapper;
	private AiService $service;

	protected function setUp(): void {
		$this->candidateMapper = $this->createMock(CandidateMapper::class);
		$eventMapper = $this->createMock(TimelineEventMapper::class);
		$eventMapper->method('findForCandidate')->willReturn([]);
		$this->service = new AiService(
			$this->createMock(ITaskProcessingManager::class),
			$this->candidateMapper,
			$this->createMock(OpeningMapper::class),
			$eventMapper,
			$this->createMock(IL10N::class),
			new NullLogger(),
		);
	}

	private function task(string $customId, string $output): Task {
		$task = new Task(TextToText::ID, ['input' => 'x'], 'recruiting', null, $customId);
		$task->setOutput(['output' => $output]);
		return $task;
	}

	private function candidate(int $id): Candidate {
		$candidate = new Candidate();
		$candidate->setId($id);
		return $candidate;
	}

	public function testIntakeFillsOnlyEmptyFields(): void {
		$candidate = $this->candidate(5);
		$candidate->setDisplayName(''); // empty → may be filled
		$candidate->setEmail('kept@example.org'); // set → must be kept
		$this->candidateMapper->method('find')->willReturn($candidate);
		$this->candidateMapper->expects($this->once())->method('update');

		$this->service->applyTaskResult($this->task('intake:5', json_encode([
			'name' => 'Ada Lovelace',
			'email' => 'other@example.org',
			'phone' => '+44 123456',
			'summary' => 'Mathematician and first programmer.',
		])));

		$this->assertSame('Ada Lovelace', $candidate->getDisplayName());
		$this->assertSame('kept@example.org', $candidate->getEmail());
		$this->assertSame('+44 123456', $candidate->getPhone());
		$this->assertSame('Mathematician and first programmer.', $candidate->getAiSummary());
	}

	public function testFencedJsonIsParsed(): void {
		$candidate = $this->candidate(5);
		$this->candidateMapper->method('find')->willReturn($candidate);
		$this->service->applyTaskResult($this->task('summary:5', "```json\n{\"summary\": \"Great fit.\"}\n```"));
		$this->assertSame('Great fit.', $candidate->getAiSummary());
	}

	public function testPlainTextFallsBackToSummary(): void {
		$candidate = $this->candidate(5);
		$this->candidateMapper->method('find')->willReturn($candidate);
		$this->service->applyTaskResult($this->task('summary:5', 'Just a plain sentence about the candidate.'));
		$this->assertSame('Just a plain sentence about the candidate.', $candidate->getAiSummary());
	}

	public function testAnonymizedCandidatesAreNeverTouched(): void {
		$candidate = $this->candidate(5);
		$candidate->setAnonymizedAt(new \DateTime());
		$this->candidateMapper->method('find')->willReturn($candidate);
		$this->candidateMapper->expects($this->never())->method('update');
		$this->service->applyTaskResult($this->task('intake:5', '{"summary": "x"}'));
	}

	public function testUnrelatedCustomIdsAreIgnored(): void {
		$this->candidateMapper->expects($this->never())->method('find');
		$this->service->applyTaskResult($this->task('draft_rejection', 'Dear …'));
	}

	/**
	 * Mail without a usable From name lands as "Unknown applicant" — exactly
	 * the case where the extracted name is needed.
	 */
	public function testPlaceholderNameIsReplaced(): void {
		$candidate = $this->candidate(5);
		$candidate->setDisplayName(\OCA\Recruiting\Service\CandidateService::UNKNOWN_APPLICANT);
		$this->candidateMapper->method('find')->willReturn($candidate);

		$this->service->applyTaskResult($this->task('intake:5', '{"name": "Grace Hopper", "summary": "s"}'));
		$this->assertSame('Grace Hopper', $candidate->getDisplayName());
	}

	public function testHumanEditedNameIsNeverOverwritten(): void {
		$candidate = $this->candidate(5);
		$candidate->setDisplayName('Corrected By Recruiter');
		$this->candidateMapper->method('find')->willReturn($candidate);

		$this->service->applyTaskResult($this->task('intake:5', '{"name": "AI Guess", "summary": "s"}'));
		$this->assertSame('Corrected By Recruiter', $candidate->getDisplayName());
	}

	public function testGarbageContactDataIsRejected(): void {
		$candidate = $this->candidate(5);
		$this->candidateMapper->method('find')->willReturn($candidate);
		$this->service->applyTaskResult($this->task('intake:5', json_encode([
			'email' => 'not-an-email',
			'phone' => 'call me maybe on weekends',
			'summary' => 's',
		])));
		$this->assertSame('', $candidate->getEmail());
		$this->assertSame('', $candidate->getPhone());
	}
}
