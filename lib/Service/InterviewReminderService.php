<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\CandidateMapper;
use OCA\Recruiting\Db\Interview;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\InterviewSlot;
use OCA\Recruiting\Db\InterviewSlotMapper;
use OCA\Recruiting\Db\MailTemplate;
use OCA\Recruiting\Db\OpeningMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Reminder mail to the candidate about a day before a confirmed interview.
 * Run hourly: every interview starting 23–25 hours from now gets exactly one
 * reminder (claimed atomically, released again when sending fails).
 *
 * Also the nudge: a candidate who has not picked a time NUDGE_AFTER_DAYS
 * after the invitation gets one friendly reminder per interview.
 */
class InterviewReminderService {
	public const WINDOW_START_HOURS = 23;
	public const WINDOW_END_HOURS = 25;
	public const NUDGE_AFTER_DAYS = 3;

	public function __construct(
		private InterviewMapper $interviewMapper,
		private CandidateMapper $candidateMapper,
		private OpeningMapper $openingMapper,
		private InterviewService $interviews,
		private MailService $mail,
		private CandidateTimeFormatter $timeFormatter,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private InterviewSlotMapper $slotMapper,
	) {
	}

	/**
	 * @return int the number of reminders sent
	 */
	public function sendDue(): int {
		$now = $this->timeFactory->getDateTime();
		$from = (clone $now)->modify('+' . self::WINDOW_START_HOURS . ' hours');
		$to = (clone $now)->modify('+' . self::WINDOW_END_HOURS . ' hours');

		$sent = 0;
		foreach ($this->interviewMapper->findDueReminders($from, $to) as $interview) {
			try {
				$candidate = $this->candidateMapper->find($interview->getCandidateId());
			} catch (DoesNotExistException) {
				continue;
			}
			if ($candidate->getAnonymizedAt() !== null || $candidate->isTerminal() || $candidate->getEmail() === '') {
				continue;
			}
			if (!$this->interviewMapper->claimReminder($interview->getId(), $now)) {
				continue;
			}
			try {
				$draft = $this->mail->preview(
					$candidate,
					$this->openingMapper->findTitle($candidate->getOpeningId()),
					MailTemplate::TYPE_INTERVIEW_REMINDER,
					null,
					null,
					$this->placeholders($interview),
				);
				$this->mail->sendToCandidate($candidate, $draft['subject'], $draft['body'], null);
				$sent++;
			} catch (\Exception $e) {
				$this->logger->warning('Recruiting: interview reminder for interview ' . $interview->getId() . ' failed: ' . $e->getMessage(), ['exception' => $e]);
				$this->interviewMapper->releaseReminder($interview->getId());
			}
		}
		return $sent;
	}

	/**
	 * Nudge candidates who were invited NUDGE_AFTER_DAYS ago and still have
	 * not picked a time — skipped when no pickable time is left, or the
	 * application is closed or anonymized.
	 *
	 * @return int the number of nudges sent
	 */
	public function sendNudges(): int {
		$now = $this->timeFactory->getDateTime();
		$cutoff = (clone $now)->modify('-' . self::NUDGE_AFTER_DAYS . ' days');

		$sent = 0;
		foreach ($this->interviewMapper->findDueNudges($cutoff) as $interview) {
			try {
				$candidate = $this->candidateMapper->find($interview->getCandidateId());
			} catch (DoesNotExistException) {
				continue;
			}
			if ($candidate->getAnonymizedAt() !== null || $candidate->isTerminal() || $candidate->getEmail() === '') {
				continue;
			}
			$pickable = array_filter(
				$this->slotMapper->findForInterview($interview->getId()),
				static fn (InterviewSlot $slot) => $slot->getStartAt() > $now && !$slot->getUnavailable(),
			);
			if ($pickable === []) {
				continue;
			}
			if (!$this->interviewMapper->claimNudge($interview->getId(), $now)) {
				continue;
			}
			try {
				$draft = $this->mail->preview(
					$candidate,
					$this->openingMapper->findTitle($candidate->getOpeningId()),
					MailTemplate::TYPE_INTERVIEW_NUDGE,
					null,
					null,
					[
						'interview_title' => $interview->getTitle(),
						'interview_link' => $this->interviews->publicUrl($interview),
					],
				);
				$this->mail->sendToCandidate($candidate, $draft['subject'], $draft['body'], null);
				$sent++;
			} catch (\Exception $e) {
				$this->logger->warning('Recruiting: interview nudge for interview ' . $interview->getId() . ' failed: ' . $e->getMessage(), ['exception' => $e]);
				$this->interviewMapper->releaseNudge($interview->getId());
			}
		}
		return $sent;
	}

	/**
	 * @return array<string, string>
	 */
	private function placeholders(Interview $interview): array {
		$link = $this->interviews->publicUrl($interview);
		$when = $interview->getStartAt() !== null
			? $this->timeFormatter->format($interview->getStartAt(), $interview->getCreatedBy())
			: ['date' => '', 'time' => ''];
		$location = $interview->getTalkLink() !== '' ? $interview->getTalkLink() : $interview->getLocation();
		return [
			'interview_title' => $interview->getTitle(),
			'interview_link' => $link,
			'interview_date' => $when['date'],
			'interview_time' => $when['time'],
			'interview_location' => $location !== '' ? $location : $link,
		];
	}
}
