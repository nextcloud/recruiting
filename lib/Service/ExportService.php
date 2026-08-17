<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Service;

use OCA\Recruiting\Db\Candidate;
use OCA\Recruiting\Db\InterviewMapper;
use OCA\Recruiting\Db\OfferMapper;
use OCA\Recruiting\Db\TimelineEventMapper;
use OCA\Recruiting\Db\VoteMapper;
use OCA\Recruiting\Exception\ValidationException;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\ITempManager;

/**
 * Data-access-request export (spec §4.8): one ZIP with the candidate's
 * structured data as JSON plus all their documents.
 *
 * Included: profile, correspondence (timeline), interviews, offer terms and
 * anonymous vote values. Deliberately excluded: reviewer identities and
 * internal team comments — those are the reviewers' personal data, not the
 * candidate's.
 */
class ExportService {
	public function __construct(
		private DocumentService $documents,
		private TimelineEventMapper $eventMapper,
		private InterviewMapper $interviewMapper,
		private OfferMapper $offerMapper,
		private VoteMapper $voteMapper,
		private ITempManager $tempManager,
		private IL10N $l10n,
	) {
	}

	/**
	 * Build the ZIP in a temp file; the caller streams it. The temp file is
	 * allocated through ITempManager, so Nextcloud cleans it up at the end of
	 * the request even when something throws in between — a stray ZIP here
	 * would be a pile of candidate PII in /tmp.
	 *
	 * @return array{path: string, name: string}
	 * @throws ValidationException
	 */
	public function buildZip(Candidate $candidate): array {
		$path = $this->tempManager->getTemporaryFile('.zip');
		if ($path === false) {
			throw new ValidationException($this->l10n->t('Could not create the export file'));
		}
		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
			throw new ValidationException($this->l10n->t('Could not create the export file'));
		}

		$id = $candidate->getId();
		$data = [
			'profile' => $candidate->jsonSerialize(),
			'timeline' => array_map(static fn ($e) => $e->jsonSerialize(), $this->eventMapper->findForCandidate($id)),
			'interviews' => array_map(static fn ($i) => $i->jsonSerialize(), $this->interviewMapper->findForCandidate($id)),
			'offers' => array_map(static fn ($o) => $o->jsonSerialize(), $this->offerMapper->findForCandidate($id)),
			'screeningVotes' => array_map(
				static fn ($v) => ['vote' => $v->getVote()], // values only, no reviewer identity
				$this->voteMapper->findForCandidate($id),
			),
		];
		$zip->addFromString('candidate.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

		// Document names are not unique per candidate (two "cv.pdf", or several
		// unnamed mail attachments): without disambiguation ZipArchive would
		// silently overwrite entries and ship an incomplete export.
		$used = [];
		foreach ($this->documents->listFor($id) as $document) {
			try {
				$content = $this->documents->getFile($document)->getContent();
			} catch (NotFoundException) {
				continue; // file vanished — skip
			}
			$zip->addFromString('documents/' . $this->uniqueEntryName($document->getName(), $used), $content);
		}
		$zip->close();

		$safeName = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $candidate->getDisplayName()) ?: 'candidate';
		return [
			'path' => $path,
			'name' => 'recruiting-export-' . trim($safeName) . '-' . $id . '.zip',
		];
	}

	/**
	 * @param array<string, true> $used
	 */
	private function uniqueEntryName(string $name, array &$used): string {
		$candidateName = $name;
		if (isset($used[$candidateName])) {
			$extension = pathinfo($name, PATHINFO_EXTENSION);
			$base = $extension !== '' ? substr($name, 0, -strlen($extension) - 1) : $name;
			$suffix = 2;
			do {
				$candidateName = $base . '-' . $suffix . ($extension !== '' ? '.' . $extension : '');
				$suffix++;
			} while (isset($used[$candidateName]));
		}
		$used[$candidateName] = true;
		return $candidateName;
	}
}
