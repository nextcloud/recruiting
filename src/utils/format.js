/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { translate as t } from '@nextcloud/l10n'
import moment from '@nextcloud/moment'

export const formatDateTime = (iso) => (iso ? moment(iso).format('llll') : '')
export const formatDate = (iso) => (iso ? moment(iso).format('ll') : '')
export const formatTime = (iso) => (iso ? moment(iso).format('LT') : '')
export const fromNow = (iso) => (iso ? moment(iso).fromNow() : '')
export const daysSince = (iso) => (iso ? Math.max(0, moment().diff(moment(iso), 'days')) : 0)

export const stageLabel = (stage) => ({
	new: t('recruiting', 'New'),
	screening: t('recruiting', 'Screening'),
	interview: t('recruiting', 'Interview'),
	offer: t('recruiting', 'Offer'),
	hired: t('recruiting', 'Hired'),
	rejected: t('recruiting', 'Rejected'),
	withdrawn: t('recruiting', 'Withdrawn'),
}[stage] ?? stage)

export const roleLabel = (role) => ({
	recruiter: t('recruiting', 'Recruiter'),
	manager: t('recruiting', 'Hiring manager'),
	interviewer: t('recruiting', 'Interviewer'),
	observer: t('recruiting', 'Observer'),
}[role] ?? role)

export const reasonLabel = (reason) => ({
	not_qualified: t('recruiting', 'Not qualified for the role'),
	better_candidate: t('recruiting', 'Another candidate was chosen'),
	position_filled: t('recruiting', 'Position was filled'),
	withdrawn: t('recruiting', 'Candidate withdrew'),
	other: t('recruiting', 'Other'),
}[reason] ?? reason)

export const sourceLabel = (source) => ({
	email: t('recruiting', 'Email application'),
	manual: t('recruiting', 'Added manually'),
	pool: t('recruiting', 'Talent pool'),
}[source] ?? source)

export const voteMeta = (vote) => ({
	yes: { label: t('recruiting', 'Yes'), emoji: '👍' },
	maybe: { label: t('recruiting', 'Maybe'), emoji: '🤔' },
	no: { label: t('recruiting', 'No'), emoji: '👎' },
}[vote] ?? { label: vote, emoji: '' })

export const interviewStatusLabel = (status) => ({
	proposed: t('recruiting', 'Awaiting candidate'),
	confirmed: t('recruiting', 'Confirmed'),
	done: t('recruiting', 'Done'),
	cancelled: t('recruiting', 'Cancelled'),
}[status] ?? status)

export const offerStatusLabel = (status) => ({
	draft: t('recruiting', 'Draft'),
	pending_approval: t('recruiting', 'Awaiting approval'),
	approved: t('recruiting', 'Approved'),
	sent: t('recruiting', 'Sent to candidate'),
	accepted: t('recruiting', 'Accepted 🎉'),
	declined: t('recruiting', 'Declined'),
	negotiating: t('recruiting', 'Negotiating'),
	expired: t('recruiting', 'Expired'),
	withdrawn: t('recruiting', 'Withdrawn'),
}[status] ?? status)

export const salaryPeriodLabel = (period) => ({
	year: t('recruiting', 'per year'),
	month: t('recruiting', 'per month'),
	hour: t('recruiting', 'per hour'),
}[period] ?? period)

export const formatSize = (bytes) => {
	if (bytes < 1024) {
		return bytes + ' B'
	}
	if (bytes < 1024 * 1024) {
		return (bytes / 1024).toFixed(0) + ' KB'
	}
	return (bytes / 1024 / 1024).toFixed(1) + ' MB'
}
