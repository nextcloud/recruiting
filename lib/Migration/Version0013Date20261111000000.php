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
 * A change counter per opening: every write to one of its candidates and
 * every timeline event recorded for them increments it, so an open board
 * asks "did anything change?" with a one-row read.
 */
class Version0013Date20261111000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$openings = $schema->getTable('recruiting_openings');
		if ($openings->hasColumn('revision')) {
			return null;
		}
		$openings->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		return $schema;
	}
}
