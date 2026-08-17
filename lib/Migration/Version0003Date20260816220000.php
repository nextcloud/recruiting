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
 * Phase 3: the talent-pool consent token (spec §4.8).
 */
class Version0003Date20260816220000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('recruiting_candidates');
		if (!$table->hasColumn('pool_consent_token')) {
			$table->addColumn('pool_consent_token', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addIndex(['pool_consent_token'], 'rcr_cand_pool_token');
		}

		return $schema;
	}
}
