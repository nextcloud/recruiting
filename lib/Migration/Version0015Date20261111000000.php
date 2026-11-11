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
 * Candidate language: the language candidate-facing mail is written in, and
 * an optional language per mail template.
 */
class Version0015Date20261111000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$candidates = $schema->getTable('recruiting_candidates');
		if (!$candidates->hasColumn('language')) {
			$candidates->addColumn('language', Types::STRING, ['notnull' => false, 'length' => 16]);
		}

		$templates = $schema->getTable('recruiting_templates');
		if (!$templates->hasColumn('language')) {
			$templates->addColumn('language', Types::STRING, ['notnull' => false, 'length' => 16]);
		}
		return $schema;
	}
}
