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
 * Initial schema for the Recruiting app.
 *
 * Later-phase columns (ai_summary, pool_member, rejection_reason, …) are already
 * part of this schema so Phase 2/3 need no disruptive migrations (see spec §8).
 */
class Version0001Date20260816000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('recruiting_openings')) {
			$table = $schema->createTable('recruiting_openings');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('department', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('location', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('employment_type', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('requirements', Types::TEXT, ['notnull' => false]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$table->addColumn('mail_slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('talk_token', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('auto_ack', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('closed_at', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['mail_slug'], 'rcr_opening_slug');
			$table->addIndex(['status'], 'rcr_opening_status');
		}

		if (!$schema->hasTable('recruiting_team')) {
			$table = $schema->createTable('recruiting_team');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('opening_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['opening_id', 'uid'], 'rcr_team_member');
			$table->addIndex(['uid'], 'rcr_team_uid');
		}

		if (!$schema->hasTable('recruiting_candidates')) {
			$table = $schema->createTable('recruiting_candidates');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('opening_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('display_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('phone', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'manual']);
			$table->addColumn('stage', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'new']);
			$table->addColumn('stage_changed_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('ai_summary', Types::TEXT, ['notnull' => false]);
			$table->addColumn('rejection_reason', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('rejected_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('hired_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('withdrawn_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('pool_member', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('pool_consent_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('anonymized_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('duplicate_of', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['opening_id', 'stage'], 'rcr_cand_pipeline');
			$table->addIndex(['email'], 'rcr_cand_email');
		}

		if (!$schema->hasTable('recruiting_documents')) {
			$table = $schema->createTable('recruiting_documents');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('candidate_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('mime', Types::STRING, ['notnull' => true, 'length' => 127, 'default' => 'application/octet-stream']);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('file_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('uploaded_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['candidate_id'], 'rcr_doc_candidate');
		}

		if (!$schema->hasTable('recruiting_assignments')) {
			$table = $schema->createTable('recruiting_assignments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('candidate_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('assigned_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['candidate_id', 'uid'], 'rcr_assign_unique');
			$table->addIndex(['uid'], 'rcr_assign_uid');
		}

		if (!$schema->hasTable('recruiting_votes')) {
			$table = $schema->createTable('recruiting_votes');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('candidate_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('vote', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('comment', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['candidate_id', 'uid'], 'rcr_vote_unique');
		}

		if (!$schema->hasTable('recruiting_interviews')) {
			$table = $schema->createTable('recruiting_interviews');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('candidate_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'proposed']);
			$table->addColumn('start_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('end_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('duration_min', Types::INTEGER, ['notnull' => true, 'default' => 60]);
			$table->addColumn('is_video', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('location', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('talk_link', Types::STRING, ['notnull' => true, 'length' => 512, 'default' => '']);
			$table->addColumn('talk_token', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$table->addColumn('calendar_uid', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('public_token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['candidate_id'], 'rcr_intv_candidate');
			$table->addUniqueIndex(['public_token'], 'rcr_intv_token');
		}

		if (!$schema->hasTable('recruiting_intv_attendees')) {
			$table = $schema->createTable('recruiting_intv_attendees');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('interview_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['interview_id', 'uid'], 'rcr_intv_att_unique');
			$table->addIndex(['uid'], 'rcr_intv_att_uid');
		}

		if (!$schema->hasTable('recruiting_intv_slots')) {
			$table = $schema->createTable('recruiting_intv_slots');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('interview_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('start_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('end_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('chosen', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['interview_id'], 'rcr_slot_interview');
		}

		if (!$schema->hasTable('recruiting_events')) {
			$table = $schema->createTable('recruiting_events');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('candidate_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('data', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['candidate_id', 'created_at'], 'rcr_event_candidate');
		}

		if (!$schema->hasTable('recruiting_templates')) {
			$table = $schema->createTable('recruiting_templates');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('body', Types::TEXT, ['notnull' => true]);
			$table->addColumn('is_default', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['type'], 'rcr_template_type');
		}

		return $schema;
	}
}
