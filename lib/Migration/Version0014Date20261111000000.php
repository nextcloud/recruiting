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
 * Interviews: feedback per round, calendar holds for proposed slots,
 * slots that turned out to be taken, and the candidate nudge.
 */
class Version0014Date20261111000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$interviews = $schema->getTable('recruiting_interviews');
		if (!$interviews->hasColumn('hold_slots')) {
			$interviews->addColumn('hold_slots', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		}
		if (!$interviews->hasColumn('invited_at')) {
			$interviews->addColumn('invited_at', Types::DATETIME, ['notnull' => false]);
		}
		if (!$interviews->hasColumn('nudge_sent_at')) {
			$interviews->addColumn('nudge_sent_at', Types::DATETIME, ['notnull' => false]);
		}
		if (!$interviews->hasIndex('rcr_intv_status_end')) {
			$interviews->addIndex(['status', 'end_at'], 'rcr_intv_status_end');
		}

		$slots = $schema->getTable('recruiting_intv_slots');
		if (!$slots->hasColumn('unavailable')) {
			$slots->addColumn('unavailable', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		}

		if (!$schema->hasTable('recruiting_intv_feedback')) {
			$table = $schema->createTable('recruiting_intv_feedback');
			$table->addColumn('id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('interview_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('verdict', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['interview_id', 'uid'], 'rcr_fb_interview_uid');
		}
		return $schema;
	}
}
