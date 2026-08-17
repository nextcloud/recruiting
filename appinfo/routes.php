<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// SPA entry (hash-based routing on the client)
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#handbook', 'url' => '/handbook', 'verb' => 'GET'],

		// Bootstrap
		['name' => 'config#session', 'url' => '/api/session', 'verb' => 'GET'],
		['name' => 'config#getPersonal', 'url' => '/api/personal', 'verb' => 'GET'],
		['name' => 'config#ingestionStatus', 'url' => '/api/ingestion-status', 'verb' => 'GET'],
		['name' => 'config#updatePersonal', 'url' => '/api/personal', 'verb' => 'PUT'],

		// Openings
		['name' => 'opening#index', 'url' => '/api/openings', 'verb' => 'GET'],
		['name' => 'opening#show', 'url' => '/api/openings/{id}', 'verb' => 'GET'],
		['name' => 'opening#create', 'url' => '/api/openings', 'verb' => 'POST'],
		['name' => 'opening#update', 'url' => '/api/openings/{id}', 'verb' => 'PUT'],
		['name' => 'opening#setStatus', 'url' => '/api/openings/{id}/status', 'verb' => 'POST'],
		['name' => 'opening#createTalkRoom', 'url' => '/api/openings/{id}/talk-room', 'verb' => 'POST'],
		['name' => 'opening#removeTalkRoom', 'url' => '/api/openings/{id}/talk-room', 'verb' => 'DELETE'],

		// Candidates
		['name' => 'candidate#index', 'url' => '/api/openings/{openingId}/candidates', 'verb' => 'GET'],
		['name' => 'candidate#triage', 'url' => '/api/triage', 'verb' => 'GET'],
		['name' => 'candidate#myReviews', 'url' => '/api/my-reviews', 'verb' => 'GET'],
		['name' => 'candidate#myInterviews', 'url' => '/api/my-interviews', 'verb' => 'GET'],
		['name' => 'candidate#show', 'url' => '/api/candidates/{id}', 'verb' => 'GET'],
		['name' => 'candidate#create', 'url' => '/api/candidates', 'verb' => 'POST'],
		['name' => 'candidate#update', 'url' => '/api/candidates/{id}', 'verb' => 'PUT'],
		['name' => 'candidate#setStage', 'url' => '/api/candidates/{id}/stage', 'verb' => 'POST'],
		['name' => 'candidate#assignOpening', 'url' => '/api/candidates/{id}/opening', 'verb' => 'POST'],
		['name' => 'candidate#reject', 'url' => '/api/candidates/{id}/reject', 'verb' => 'POST'],
		['name' => 'candidate#export', 'url' => '/api/candidates/{id}/export', 'verb' => 'GET'],
		['name' => 'candidate#destroy', 'url' => '/api/candidates/{id}', 'verb' => 'DELETE'],

		// Talent pool (Phase 3)
		['name' => 'candidate#pool', 'url' => '/api/pool', 'verb' => 'GET'],
		['name' => 'candidate#addPoolToOpening', 'url' => '/api/pool/{id}/add-to-opening', 'verb' => 'POST'],

		// AI assistance (Phase 3)
		['name' => 'ai#request', 'url' => '/api/candidates/{candidateId}/ai', 'verb' => 'POST'],
		['name' => 'ai#taskResult', 'url' => '/api/ai-tasks/{taskId}', 'verb' => 'GET'],

		// Offers (Phase 2)
		['name' => 'offer#create', 'url' => '/api/candidates/{candidateId}/offers', 'verb' => 'POST'],
		['name' => 'offer#update', 'url' => '/api/offers/{id}', 'verb' => 'PUT'],
		['name' => 'offer#submit', 'url' => '/api/offers/{id}/submit', 'verb' => 'POST'],
		['name' => 'offer#approve', 'url' => '/api/offers/{id}/approve', 'verb' => 'POST'],
		['name' => 'offer#declineApproval', 'url' => '/api/offers/{id}/decline-approval', 'verb' => 'POST'],
		['name' => 'offer#send', 'url' => '/api/offers/{id}/send', 'verb' => 'POST'],
		['name' => 'offer#respond', 'url' => '/api/offers/{id}/respond', 'verb' => 'POST'],
		['name' => 'offer#withdraw', 'url' => '/api/offers/{id}/withdraw', 'verb' => 'POST'],

		// Reports (Phase 2)
		['name' => 'report#overview', 'url' => '/api/reports', 'verb' => 'GET'],

		// Documents
		['name' => 'document#upload', 'url' => '/api/candidates/{candidateId}/documents', 'verb' => 'POST'],
		['name' => 'document#show', 'url' => '/api/documents/{id}', 'verb' => 'GET'],
		['name' => 'document#destroy', 'url' => '/api/documents/{id}', 'verb' => 'DELETE'],

		// Screening
		['name' => 'screening#assign', 'url' => '/api/candidates/{candidateId}/screeners', 'verb' => 'POST'],
		['name' => 'screening#unassign', 'url' => '/api/candidates/{candidateId}/screeners/{uid}', 'verb' => 'DELETE'],
		['name' => 'screening#vote', 'url' => '/api/candidates/{candidateId}/vote', 'verb' => 'PUT'],

		// Comments (discussion thread, backed by OCP\Comments)
		['name' => 'comment#index', 'url' => '/api/candidates/{candidateId}/comments', 'verb' => 'GET'],
		['name' => 'comment#create', 'url' => '/api/candidates/{candidateId}/comments', 'verb' => 'POST'],

		// Interviews
		['name' => 'interview#proposeSlots', 'url' => '/api/candidates/{candidateId}/slot-proposals', 'verb' => 'POST'],
		['name' => 'interview#create', 'url' => '/api/candidates/{candidateId}/interviews', 'verb' => 'POST'],
		['name' => 'interview#sendInvite', 'url' => '/api/interviews/{id}/invite', 'verb' => 'POST'],
		['name' => 'interview#cancel', 'url' => '/api/interviews/{id}/cancel', 'verb' => 'POST'],

		// Mail
		['name' => 'mail#preview', 'url' => '/api/candidates/{candidateId}/mail-preview', 'verb' => 'POST'],
		['name' => 'template#index', 'url' => '/api/templates', 'verb' => 'GET'],
		['name' => 'template#create', 'url' => '/api/templates', 'verb' => 'POST'],
		['name' => 'template#update', 'url' => '/api/templates/{id}', 'verb' => 'PUT'],
		['name' => 'template#destroy', 'url' => '/api/templates/{id}', 'verb' => 'DELETE'],

		// People picker
		['name' => 'user#search', 'url' => '/api/users/search', 'verb' => 'GET'],

		// Admin settings
		['name' => 'settings#get', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'settings#update', 'url' => '/api/admin/settings', 'verb' => 'PUT'],
		['name' => 'settings#testImap', 'url' => '/api/admin/imap-test', 'verb' => 'POST'],
		['name' => 'settings#groups', 'url' => '/api/admin/groups', 'verb' => 'GET'],

		// Public candidate pages (no login)
		['name' => 'public#showInterview', 'url' => '/i/{token}', 'verb' => 'GET'],
		['name' => 'public#confirmSlot', 'url' => '/i/{token}/confirm', 'verb' => 'POST'],
		['name' => 'public#interviewIcs', 'url' => '/i/{token}/event.ics', 'verb' => 'GET'],
		['name' => 'public#showPoolConsent', 'url' => '/pool/{token}', 'verb' => 'GET'],
		['name' => 'public#confirmPoolConsent', 'url' => '/pool/{token}/confirm', 'verb' => 'POST'],
	],
];
