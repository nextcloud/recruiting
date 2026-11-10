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
 * Failed-ingestion bookkeeping in the Message-ID ledger: a mail that fails is
 * left unseen and retried; the ledger counts the attempts and, after the
 * third, records when the mail was moved to the quarantine folder. `pending`
 * marks rows that only track failures — existing rows are ingested mails.
 */
class Version0006Date20261110000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('recruiting_mail_seen');
		if (!$table->hasColumn('pending')) {
			$table->addColumn('pending', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		}
		if (!$table->hasColumn('attempts')) {
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		}
		if (!$table->hasColumn('last_error')) {
			$table->addColumn('last_error', Types::STRING, ['notnull' => false, 'length' => 255]);
		}
		if (!$table->hasColumn('quarantined_at')) {
			$table->addColumn('quarantined_at', Types::DATETIME, ['notnull' => false]);
		}
		return $schema;
	}
}
