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
 * Resumable mail intake: the ledger row of a mail remembers the candidate
 * created for it, so a retry continues on that candidate instead of
 * creating another one.
 */
class Version0012Date20261111000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('recruiting_mail_seen');
		if (!$table->hasColumn('candidate_id')) {
			$table->addColumn('candidate_id', Types::BIGINT, ['notnull' => false]);
		}
		return $schema;
	}
}
