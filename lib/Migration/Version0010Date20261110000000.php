<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Candidate experience: extracted document text for AI and search, the
 * candidate self-service portal (token, deletion request), interview
 * reminders and employee referrals.
 */
class Version0010Date20261110000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$documents = $schema->getTable('recruiting_documents');
		if (!$documents->hasColumn('text_content')) {
			$documents->addColumn('text_content', Types::TEXT, ['notnull' => false]);
		}

		$candidates = $schema->getTable('recruiting_candidates');
		if (!$candidates->hasColumn('portal_token')) {
			$candidates->addColumn('portal_token', Types::STRING, ['notnull' => false, 'length' => 64]);
			$candidates->addUniqueIndex(['portal_token'], 'rcr_cand_portal_token');
		}
		if (!$candidates->hasColumn('deletion_requested_at')) {
			$candidates->addColumn('deletion_requested_at', Types::DATETIME, ['notnull' => false]);
		}
		if (!$candidates->hasColumn('referred_by')) {
			$candidates->addColumn('referred_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$candidates->addIndex(['referred_by'], 'rcr_cand_referred_by');
		}

		$interviews = $schema->getTable('recruiting_interviews');
		if (!$interviews->hasColumn('reminder_sent_at')) {
			$interviews->addColumn('reminder_sent_at', Types::DATETIME, ['notnull' => false]);
		}
		return $schema;
	}
}
