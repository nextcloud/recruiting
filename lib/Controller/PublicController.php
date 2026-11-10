<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Controller;

use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCA\Recruiting\Service\ApplicationFormToken;
use OCA\Recruiting\Service\InterviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Defaults;
use OCP\IRequest;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * The candidate-facing pages: pick an interview slot via a tokenized
 * link, no account required (spec §4.4). Rate limited and brute-force
 * protected.
 */
class PublicController extends Controller {
	public const MAX_NAME_LENGTH = 120;

	public function __construct(
		string $appName,
		IRequest $request,
		private InterviewMapper $interviewMapper,
		private InterviewService $interviews,
		private \OCA\Recruiting\Service\PoolService $pool,
		private \OCA\Recruiting\Db\OpeningMapper $openingMapper,
		private \OCA\Recruiting\Service\IngestionService $ingestion,
		private ApplicationFormToken $formToken,
		private IInitialState $initialState,
		private Defaults $defaults,
		private \OCP\IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'recruitingPublicInterview')]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function showInterview(string $token): TemplateResponse {
		$interview = $this->lookup($token);
		if ($interview === null) {
			$response = new TemplateResponse($this->appName, 'public-notfound', [], TemplateResponse::RENDER_AS_GUEST);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'recruitingPublicInterview']);
			return $response;
		}

		$this->initialState->provideInitialState('publicInterview', $this->interviews->publicData($interview));
		$this->initialState->provideInitialState('publicToken', $token);
		Util::addScript($this->appName, 'recruiting-public-interview');

		$response = new TemplateResponse($this->appName, 'public-interview', [], TemplateResponse::RENDER_AS_GUEST);
		$response->setContentSecurityPolicy(new ContentSecurityPolicy());
		return $response;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'recruitingPublicInterview')]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function confirmSlot(string $token, int $slotId): DataResponse {
		$interview = $this->lookup($token);
		if ($interview === null) {
			$response = new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'recruitingPublicInterview']);
			return $response;
		}
		try {
			$this->interviews->confirmSlot($interview, $slotId);
			// Re-read so the page shows the booked state; if the row vanished
			// meanwhile, fall back to the object we already hold rather than
			// failing a confirmation that actually succeeded.
			$confirmed = $this->interviewMapper->findByToken($token) ?? $interview;
			return new DataResponse($this->interviews->publicData($confirmed));
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			$this->logger->error('Confirming interview slot failed: ' . $e->getMessage(), ['exception' => $e]);
			return new DataResponse(['message' => 'An unexpected error occurred.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * "None of these times work for me" — with an optional message to the
	 * team, who then propose new times.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'recruitingPublicInterview')]
	#[AnonRateLimit(limit: 5, period: 60)]
	public function declineSlots(string $token, string $message = ''): DataResponse {
		return $this->candidateRequest($token, fn (\OCA\Recruiting\Db\Interview $interview) => $this->interviews->declineSlots($interview, $message));
	}

	/**
	 * "I need to reschedule" on a confirmed interview. The booking stays
	 * until the team reacts.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'recruitingPublicInterview')]
	#[AnonRateLimit(limit: 5, period: 60)]
	public function requestReschedule(string $token, string $message = ''): DataResponse {
		return $this->candidateRequest($token, fn (\OCA\Recruiting\Db\Interview $interview) => $this->interviews->requestReschedule($interview, $message));
	}

	/**
	 * @param \Closure(\OCA\Recruiting\Db\Interview): void $action
	 */
	private function candidateRequest(string $token, \Closure $action): DataResponse {
		$interview = $this->lookup($token);
		if ($interview === null) {
			$response = new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'recruitingPublicInterview']);
			return $response;
		}
		try {
			$action($interview);
			return new DataResponse(['sent' => true]);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			$this->logger->error('Recruiting: interview request from the candidate failed: ' . $e->getMessage(), ['exception' => $e]);
			return new DataResponse(['message' => 'An unexpected error occurred.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Calendar file for a confirmed interview, so the candidate's own
	 * calendar knows about the appointment too — the interviewers get a
	 * calendar event, this is the candidate's counterpart.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'recruitingPublicInterview')]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function interviewIcs(string $token): Response {
		$interview = $this->lookup($token);
		if ($interview === null
			|| $interview->getStatus() !== \OCA\Recruiting\Db\Interview::STATUS_CONFIRMED
			|| $interview->getStartAt() === null
			|| $interview->getEndAt() === null) {
			$response = new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'recruitingPublicInterview']);
			return $response;
		}

		$escape = static fn (string $text): string => str_replace(
			['\\', ';', ',', "\n"],
			['\\\\', '\\;', '\\,', '\\n'],
			$text,
		);
		$utc = new \DateTimeZone('UTC');
		$stamp = static fn (\DateTime $date): string => (clone $date)->setTimezone($utc)->format('Ymd\THis\Z');

		$summary = $interview->getTitle() . ' — ' . $this->defaults->getName();
		$location = $interview->getTalkLink() !== '' ? $interview->getTalkLink() : $interview->getLocation();

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Nextcloud Recruiting//EN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:recruiting-interview-' . $escape($token) . '@' . $this->request->getServerHost(),
			'DTSTAMP:' . $stamp($interview->getStartAt()),
			'DTSTART:' . $stamp($interview->getStartAt()),
			'DTEND:' . $stamp($interview->getEndAt()),
			'SUMMARY:' . $escape($summary),
		];
		if ($location !== '') {
			$lines[] = 'LOCATION:' . $escape($location);
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return new DataDownloadResponse(implode("\r\n", $lines) . "\r\n", 'interview.ics', 'text/calendar');
	}

	/**
	 * Talent-pool consent page: a plain HTML form, no scripts needed.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'recruitingPoolConsent')]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function showPoolConsent(string $token): TemplateResponse {
		$candidate = $this->pool->findByToken($token);
		if ($candidate === null) {
			$response = new TemplateResponse($this->appName, 'public-notfound', [], TemplateResponse::RENDER_AS_GUEST);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'recruitingPoolConsent']);
			return $response;
		}
		return new TemplateResponse($this->appName, 'public-pool', [
			'name' => $candidate->getDisplayName(),
			'confirmed' => $candidate->getPoolMember(),
			'token' => $token,
		], TemplateResponse::RENDER_AS_GUEST);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'recruitingPoolConsent')]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function confirmPoolConsent(string $token): TemplateResponse {
		$candidate = $this->pool->findByToken($token);
		if ($candidate === null) {
			$response = new TemplateResponse($this->appName, 'public-notfound', [], TemplateResponse::RENDER_AS_GUEST);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'recruitingPoolConsent']);
			return $response;
		}
		$this->pool->confirm($candidate);
		return new TemplateResponse($this->appName, 'public-pool', [
			'name' => $candidate->getDisplayName(),
			'confirmed' => true,
			'token' => $token,
		], TemplateResponse::RENDER_AS_GUEST);
	}

	/**
	 * The public application form for one opening (spec §4.13), reached at
	 * /apply/{slug}. Only openings that opted in and are still open show a form;
	 * anything else is a neutral 404 so a closed opening's slug leaks nothing.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'recruitingPublicApply')]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function showApplication(string $slug): TemplateResponse {
		$opening = $this->openApplicationOpening($slug);
		if ($opening === null) {
			return $this->applyNotFound();
		}
		return $this->applyForm($opening);
	}

	/**
	 * Receive a submission. Guarded by brute-force + rate limits (attributes), a
	 * hidden honeypot field, and a signed fill-time token — a bot that posts
	 * instantly, sends no or a forged token, or fills the invisible field is
	 * dropped without creating anything. An expired token (form open for more
	 * than a day) re-renders the form with a fresh token. Consent
	 * is mandatory (GDPR). On success the applicant sees a thank-you page (POST /
	 * redirect / GET would need a token; a rendered confirmation is simpler and
	 * has no state to leak).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'recruitingPublicApply')]
	#[AnonRateLimit(limit: 5, period: 60)]
	public function submitApplication(string $slug): TemplateResponse {
		$opening = $this->openApplicationOpening($slug);
		if ($opening === null) {
			return $this->applyNotFound();
		}

		// Honeypot: a field hidden from humans that bots dutifully fill.
		if (trim((string)$this->request->getParam('website', '')) !== '') {
			$this->logger->info('Recruiting: public application dropped (honeypot)');
			return $this->applyThankYou($opening); // lie kindly: bots get a success page
		}
		$name = trim((string)$this->request->getParam('name', ''));
		$email = trim((string)$this->request->getParam('email', ''));
		$cover = (string)$this->request->getParam('cover', '');
		$consent = $this->request->getParam('consent', '');
		$values = ['name' => $name, 'email' => $email, 'cover' => $cover];

		switch ($this->formToken->check((string)$this->request->getParam('t', ''), $opening->getMailSlug())) {
			case ApplicationFormToken::INVALID:
				$this->logger->info('Recruiting: public application dropped (missing or invalid form token)');
				return $this->applyThankYou($opening);
			case ApplicationFormToken::TOO_FAST:
				$this->logger->info('Recruiting: public application dropped (too fast)');
				return $this->applyThankYou($opening);
			case ApplicationFormToken::EXPIRED:
				$response = $this->applyForm($opening, [$this->l10n->t('The form has expired. Please submit it again.')], $values);
				$response->setStatus(Http::STATUS_BAD_REQUEST);
				return $response;
		}

		$errors = [];
		if ($name === '') {
			$errors[] = $this->l10n->t('Please enter your name.');
		} elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			$errors[] = $this->l10n->t('Your name is too long.');
		} elseif (self::nameContainsLink($name)) {
			$errors[] = $this->l10n->t('Please enter your name without links or email addresses.');
		}
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			$errors[] = $this->l10n->t('Please enter a valid email address.');
		}
		if ($consent !== 'on' && $consent !== '1') {
			$errors[] = $this->l10n->t('Please agree to the processing of your application.');
		}
		if (mb_strlen($cover) > 20000) {
			$errors[] = $this->l10n->t('Your message is too long.');
		}

		$cv = null;
		$file = $this->request->getUploadedFile('cv');
		if (is_array($file) && ($file['error'] ?? \UPLOAD_ERR_NO_FILE) === \UPLOAD_ERR_OK) {
			$content = @file_get_contents((string)($file['tmp_name'] ?? ''));
			if ($content === false) {
				$errors[] = $this->l10n->t('The uploaded file could not be read.');
			} else {
				$cv = [
					'name' => (string)($file['name'] ?? 'CV'),
					'mime' => (string)($file['type'] ?? 'application/octet-stream'),
					'content' => $content,
				];
			}
		}

		if ($errors !== []) {
			$response = $this->applyForm($opening, $errors, $values);
			$response->setStatus(Http::STATUS_BAD_REQUEST);
			return $response;
		}

		try {
			$this->ingestion->ingestWebApplication($opening, $name, $email, $cover, $cv);
		} catch (\Throwable $e) {
			$this->logger->error('Recruiting: public application failed', ['exception' => $e]);
			$response = $this->applyForm($opening, [$this->l10n->t('Something went wrong. Please try again.')], $values);
			$response->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
			return $response;
		}

		return $this->applyThankYou($opening);
	}

	/**
	 * Whether an applicant name carries a link or address: "://", "www.",
	 * a host.tld/ path, or "@".
	 */
	public static function nameContainsLink(string $name): bool {
		return str_contains($name, '://')
			|| str_contains($name, '@')
			|| stripos($name, 'www.') !== false
			|| preg_match('/[\p{L}\p{N}-]\.\p{L}{2,}\//u', $name) === 1;
	}

	/** An opening that currently accepts public applications, or null. */
	private function openApplicationOpening(string $slug): ?\OCA\Recruiting\Db\Opening {
		if ($slug === '' || strlen($slug) > 64) {
			return null;
		}
		$opening = $this->openingMapper->findByMailSlug($slug);
		if ($opening === null || !$opening->getPublicApply() || $opening->getStatus() !== 'open') {
			return null;
		}
		return $opening;
	}

	/**
	 * @param string[] $errors
	 * @param array{name?:string,email?:string,cover?:string} $values
	 */
	private function applyForm(\OCA\Recruiting\Db\Opening $opening, array $errors = [], array $values = []): TemplateResponse {
		$response = new TemplateResponse($this->appName, 'public-apply', [
			'opening' => $opening,
			'errors' => $errors,
			'values' => $values,
			'token' => $this->formToken->issue($opening->getMailSlug()),
			'instanceName' => $this->defaults->getName(),
			'done' => false,
		], TemplateResponse::RENDER_AS_GUEST);
		return $response;
	}

	private function applyThankYou(\OCA\Recruiting\Db\Opening $opening): TemplateResponse {
		$response = new TemplateResponse($this->appName, 'public-apply', [
			'opening' => $opening,
			'errors' => [],
			'values' => [],
			'instanceName' => $this->defaults->getName(),
			'done' => true,
		], TemplateResponse::RENDER_AS_GUEST);
		return $response;
	}

	private function applyNotFound(): TemplateResponse {
		$response = new TemplateResponse($this->appName, 'public-notfound', [], TemplateResponse::RENDER_AS_GUEST);
		$response->setStatus(Http::STATUS_NOT_FOUND);
		$response->throttle(['action' => 'recruitingPublicApply']);
		return $response;
	}

	/**
	 * Expired links (see InterviewService::findByPublicToken) are treated
	 * exactly like unknown ones.
	 */
	private function lookup(string $token): ?\OCA\Recruiting\Db\Interview {
		if (strlen($token) < 32 || !ctype_alnum($token)) {
			return null;
		}
		return $this->interviews->findByPublicToken($token);
	}
}
