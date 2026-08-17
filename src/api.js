/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/recruiting' + path)

export default {
	// Session
	getSession: () => axios.get(url('/api/session')),

	// Openings
	getOpenings: () => axios.get(url('/api/openings')),
	getOpening: (id) => axios.get(url(`/api/openings/${id}`)),
	createOpening: (data) => axios.post(url('/api/openings'), data),
	updateOpening: (id, data) => axios.put(url(`/api/openings/${id}`), data),
	setOpeningStatus: (id, status) => axios.post(url(`/api/openings/${id}/status`), { status }),

	// Candidates
	getCandidates: (openingId, params = {}) => axios.get(url(`/api/openings/${openingId}/candidates`), { params }),
	getTriage: () => axios.get(url('/api/triage')),
	getIngestionStatus: () => axios.get(url('/api/ingestion-status')),
	getMyReviews: () => axios.get(url('/api/my-reviews')),
	getMyInterviews: () => axios.get(url('/api/my-interviews')),
	getCandidate: (id) => axios.get(url(`/api/candidates/${id}`)),
	createCandidate: (data) => axios.post(url('/api/candidates'), data),
	updateCandidate: (id, data) => axios.put(url(`/api/candidates/${id}`), data),
	setStage: (id, stage) => axios.post(url(`/api/candidates/${id}/stage`), { stage }),
	assignOpening: (id, openingId) => axios.post(url(`/api/candidates/${id}/opening`), { openingId }),
	rejectCandidate: (id, data) => axios.post(url(`/api/candidates/${id}/reject`), data),
	deleteCandidate: (id) => axios.delete(url(`/api/candidates/${id}`)),

	// Documents
	uploadDocument: (candidateId, file) => {
		const form = new FormData()
		form.append('file', file, file.name)
		return axios.post(url(`/api/candidates/${candidateId}/documents`), form)
	},
	documentUrl: (id) => url(`/api/documents/${id}`),
	deleteDocument: (id) => axios.delete(url(`/api/documents/${id}`)),

	// Screening
	assignScreeners: (candidateId, uids) => axios.post(url(`/api/candidates/${candidateId}/screeners`), { uids }),
	unassignScreener: (candidateId, uid) => axios.delete(url(`/api/candidates/${candidateId}/screeners/${encodeURIComponent(uid)}`)),
	vote: (candidateId, vote, comment) => axios.put(url(`/api/candidates/${candidateId}/vote`), { vote, comment }),

	// Comments
	getComments: (candidateId) => axios.get(url(`/api/candidates/${candidateId}/comments`)),
	addComment: (candidateId, message) => axios.post(url(`/api/candidates/${candidateId}/comments`), { message }),

	// Interviews
	proposeSlots: (candidateId, data) => axios.post(url(`/api/candidates/${candidateId}/slot-proposals`), data),
	createInterview: (candidateId, data) => axios.post(url(`/api/candidates/${candidateId}/interviews`), data),
	sendInvite: (interviewId, subject, body) => axios.post(url(`/api/interviews/${interviewId}/invite`), { subject, body }),
	cancelInterview: (interviewId) => axios.post(url(`/api/interviews/${interviewId}/cancel`)),

	// Offers
	createOffer: (candidateId, data) => axios.post(url(`/api/candidates/${candidateId}/offers`), data),
	updateOffer: (id, data) => axios.put(url(`/api/offers/${id}`), data),
	submitOffer: (id, approverUid) => axios.post(url(`/api/offers/${id}/submit`), { approverUid }),
	approveOffer: (id) => axios.post(url(`/api/offers/${id}/approve`)),
	declineOfferApproval: (id, note) => axios.post(url(`/api/offers/${id}/decline-approval`), { note }),
	sendOffer: (id, data = {}) => axios.post(url(`/api/offers/${id}/send`), data),
	respondOffer: (id, response) => axios.post(url(`/api/offers/${id}/respond`), { response }),
	withdrawOffer: (id) => axios.post(url(`/api/offers/${id}/withdraw`)),

	// Reports
	getReports: () => axios.get(url('/api/reports')),

	// AI assistance
	aiRequest: (candidateId, action) => axios.post(url(`/api/candidates/${candidateId}/ai`), { action }),
	aiTask: (taskId) => axios.get(url(`/api/ai-tasks/${taskId}`)),

	// Talent pool
	getPool: () => axios.get(url('/api/pool')),
	addPoolToOpening: (id, openingId) => axios.post(url(`/api/pool/${id}/add-to-opening`), { openingId }),

	// Talk room per opening
	createTalkRoom: (openingId) => axios.post(url(`/api/openings/${openingId}/talk-room`)),
	removeTalkRoom: (openingId) => axios.delete(url(`/api/openings/${openingId}/talk-room`)),

	// Personal settings
	getPersonal: () => axios.get(url('/api/personal')),
	updatePersonal: (digest) => axios.put(url('/api/personal'), { digest }),

	// GDPR export
	exportUrl: (candidateId) => url(`/api/candidates/${candidateId}/export`),

	// Mail
	previewMail: (candidateId, type, templateId = null) => axios.post(url(`/api/candidates/${candidateId}/mail-preview`), { type, templateId }),
	getTemplates: () => axios.get(url('/api/templates')),
	createTemplate: (data) => axios.post(url('/api/templates'), data),
	updateTemplate: (id, data) => axios.put(url(`/api/templates/${id}`), data),
	deleteTemplate: (id) => axios.delete(url(`/api/templates/${id}`)),

	// People picker
	searchUsers: (query) => axios.get(url('/api/users/search'), { params: { query } }),

	// Admin settings
	getSettings: () => axios.get(url('/api/admin/settings')),
	updateSettings: (data) => axios.put(url('/api/admin/settings'), data),
	testImap: (data) => axios.post(url('/api/admin/imap-test'), data),
	getGroups: () => axios.get(url('/api/admin/groups')),
}

/**
 * Extract a human-readable message from an API error response.
 *
 * @param {Error} error axios error
 * @param {string} fallback message when the response carries none
 * @return {string}
 */
export function errorMessage(error, fallback) {
	return error?.response?.data?.message || fallback
}
