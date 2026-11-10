<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Tests\Unit;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Opening;
use OCA\Recruiting\Db\OpeningMapper;
use OCA\Recruiting\Db\SeenMailMapper;
use OCA\Recruiting\Db\TeamMemberMapper;
use OCA\Recruiting\Db\TimelineEvent;
use OCA\Recruiting\Service\AiService;
use OCA\Recruiting\Service\CandidateService;
use OCA\Recruiting\Service\ConfigService;
use OCA\Recruiting\Service\DocumentService;
use OCA\Recruiting\Service\IngestionService;
use OCA\Recruiting\Service\MailService;
use OCA\Recruiting\Service\NotificationService;
use OCA\Recruiting\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The public application form (spec §4.13) funnels through the same intake
 * pipeline an emailed application uses: a candidate, a timeline entry with the
 * cover letter, the CV as a document, and AI intake analysis.
 */
class WebApplicationTest extends TestCase {
	private CandidateService&MockObject $candidates;
	private DocumentService&MockObject $documents;
	private TimelineService&MockObject $timeline;
	private AiService&MockObject $ai;
	private MailService&MockObject $mail;
	private IngestionService $service;

	protected function setUp(): void {
		$this->candidates = $this->createMock(CandidateService::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->ai = $this->createMock(AiService::class);
		$this->mail = $this->createMock(MailService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());

		$this->service = new IngestionService(
			$this->createMock(ConfigService::class),
			$this->createMock(OpeningMapper::class),
			$this->createMock(CandidateMapper::class),
			$this->createMock(SeenMailMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->candidates,
			$this->documents,
			$this->mail,
			$this->timeline,
			$this->createMock(NotificationService::class),
			$this->ai,
			$time,
			new NullLogger(),
		);
	}

	private function opening(bool $autoAck = false): Opening {
		$opening = new Opening();
		$opening->setId(7);
		$opening->setTitle('Backend Engineer');
		$opening->setStatus('open');
		$opening->setAutoAck($autoAck);
		return $opening;
	}

	private function candidateFor(string $email): Candidate {
		$c = new Candidate();
		$c->setId(55);
		$c->setEmail($email);
		$c->setSource(Candidate::SOURCE_WEB);
		return $c;
	}

	public function testCreatesCandidateTimelineAndDocument(): void {
		$candidate = $this->candidateFor('ada@example.org');
		$this->candidates->expects($this->once())->method('insertCandidate')
			->with(7, 'Ada Lovelace', 'ada@example.org', '', Candidate::SOURCE_WEB, null)
			->willReturn($candidate);
		$this->timeline->expects($this->once())->method('record')
			->with(55, TimelineEvent::TYPE_WEB_APPLICATION, null, $this->callback(
				static fn (array $d) => str_contains((string)$d['body'], 'I build APIs'),
			));
		$this->documents->expects($this->once())->method('add')
			->with($candidate, 'cv.pdf', 'application/pdf', 'PDFBYTES', null);
		$this->ai->expects($this->once())->method('scheduleIntakeAnalysis')->with($candidate);

		$result = $this->service->ingestWebApplication(
			$this->opening(),
			'Ada Lovelace',
			'ada@example.org',
			'I build APIs.',
			['name' => 'cv.pdf', 'mime' => 'application/pdf', 'content' => 'PDFBYTES'],
		);

		$this->assertSame($candidate, $result);
	}

	public function testNoDocumentWhenNoCvProvided(): void {
		$this->candidates->method('insertCandidate')->willReturn($this->candidateFor('x@example.org'));
		$this->documents->expects($this->never())->method('add');

		$this->service->ingestWebApplication($this->opening(), 'X', 'x@example.org', '', null);
	}

	public function testAutoAckSentOnlyWhenEnabledAndEmailPresent(): void {
		$this->candidates->method('insertCandidate')->willReturn($this->candidateFor('a@example.org'));
		$this->mail->method('preview')->willReturn(['subject' => 'Received', 'body' => 'Thanks']);
		$this->mail->expects($this->once())->method('sendToCandidate');

		$this->service->ingestWebApplication($this->opening(autoAck: true), 'A', 'a@example.org', '', null);
	}

	public function testNoAutoAckWhenDisabled(): void {
		$this->candidates->method('insertCandidate')->willReturn($this->candidateFor('a@example.org'));
		$this->mail->expects($this->never())->method('sendToCandidate');

		$this->service->ingestWebApplication($this->opening(autoAck: false), 'A', 'a@example.org', '', null);
	}

	public function testAFailingCvUploadDoesNotAbortTheApplication(): void {
		// A rejected attachment (wrong type/too large) must not lose the whole
		// application — mirrors the emailed-application behaviour.
		$candidate = $this->candidateFor('a@example.org');
		$this->candidates->method('insertCandidate')->willReturn($candidate);
		$this->documents->method('add')->willThrowException(
			new \OCA\Recruiting\Exception\ValidationException('unsupported type'),
		);
		$this->ai->expects($this->once())->method('scheduleIntakeAnalysis');

		$result = $this->service->ingestWebApplication(
			$this->opening(),
			'A',
			'a@example.org',
			'hi',
			['name' => 'virus.exe', 'mime' => 'application/x-dosexec', 'content' => 'MZ'],
		);
		$this->assertSame($candidate, $result);
	}
}
