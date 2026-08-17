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
 * Reply threading (spec §4.2): a per-candidate reply token so answers to
 * our mails land on the candidate's timeline instead of becoming a new
 * application, and a Message-ID ledger so a crash between processing and
 * marking a mail seen cannot ingest the same application twice.
 */
class Version0004Date20260817000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('recruiting_candidates');
		if (!$table->hasColumn('reply_token')) {
			$table->addColumn('reply_token', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		if (!$schema->hasTable('recruiting_mail_seen')) {
			$table = $schema->createTable('recruiting_mail_seen');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('message_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['message_id'], 'rcr_mail_seen_mid');
		}

		return $schema;
	}
}
