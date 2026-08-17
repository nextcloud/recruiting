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
 * Phase 2: the offers table (spec §4.6).
 */
class Version0002Date20260816200000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('recruiting_offers')) {
			$table = $schema->createTable('recruiting_offers');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('candidate_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('job_title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('salary_amount', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$table->addColumn('salary_currency', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'EUR']);
			$table->addColumn('salary_period', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'year']);
			$table->addColumn('start_date', Types::DATE, ['notnull' => false]);
			$table->addColumn('valid_until', Types::DATE, ['notnull' => false]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 24, 'default' => 'draft']);
			$table->addColumn('approver_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('approved_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('responded_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('expiry_notified', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['candidate_id'], 'rcr_offer_candidate');
			$table->addIndex(['status'], 'rcr_offer_status');
			$table->addIndex(['approver_uid'], 'rcr_offer_approver');
		}

		return $schema;
	}
}
