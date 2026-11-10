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
 * Public application form (spec §4.13): an opening can opt in to a public
 * apply page reachable at /apply/{mail_slug}, so applicants no longer need to
 * find an email address. Off by default — a recruiter turns it on per opening.
 */
class Version0005Date20260819000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('recruiting_openings');
		if (!$table->hasColumn('public_apply')) {
			$table->addColumn('public_apply', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		}
		return $schema;
	}
}
