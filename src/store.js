/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import api, { errorMessage } from './api.js'

export const useSessionStore = defineStore('session', {
	state: () => ({
		...loadState('recruiting', 'session', {
			uid: '',
			displayName: '',
			isRecruiter: false,
			talkAvailable: false,
			triageCount: 0,
			stages: ['new', 'screening', 'interview', 'offer'],
			terminalStages: ['hired', 'rejected', 'withdrawn'],
			rejectionReasons: [],
		}),
	}),
	getters: {
		// The stage taxonomy comes from the server — never hardcode it in a view
		isTerminalStage: (state) => (stage) => state.terminalStages.includes(stage),
		isActiveStage: (state) => (stage) => state.stages.includes(stage),
		/**
		 * Board columns plus "Hired", the funnel the reports draw
		 *
		 * @param state
		 */
		funnelStages: (state) => [...state.stages, 'hired'],
	},
	actions: {
		async refresh() {
			const { data } = await api.getSession()
			Object.assign(this, data)
		},
	},
})

export const useOpeningsStore = defineStore('openings', {
	state: () => ({
		openings: [],
		loaded: false,
		loading: false,
	}),
	getters: {
		open: (state) => state.openings.filter((o) => o.status !== 'closed'),
		closed: (state) => state.openings.filter((o) => o.status === 'closed'),
		byId: (state) => (id) => state.openings.find((o) => o.id === Number(id)) ?? null,
		manageable: (state) => state.openings.filter((o) => ['recruiter', 'manager'].includes(o.myRole) && o.status === 'open'),
	},
	actions: {
		async load(force = false) {
			if (this.loaded && !force) {
				return
			}
			this.loading = true
			try {
				const { data } = await api.getOpenings()
				this.openings = data
				this.loaded = true
			} finally {
				this.loading = false
			}
		},
		upsert(opening) {
			const index = this.openings.findIndex((o) => o.id === opening.id)
			if (index >= 0) {
				this.openings.splice(index, 1, opening)
			} else {
				this.openings.unshift(opening)
			}
		},
	},
})

/**
 * The candidate detail sidebar is global UI state: any view can open it.
 */
export const useSidebarStore = defineStore('sidebar', {
	state: () => ({
		candidateId: null,
		candidate: null,
		loading: false,
		/** bumped by mutations elsewhere so list views know to refresh */
		version: 0,
		/** guards against out-of-order responses (see reload) */
		requestToken: 0,
	}),
	actions: {
		async open(candidateId) {
			this.candidateId = candidateId
			this.candidate = null
			await this.reload()
		},
		/**
		 * Requests can overtake each other when candidates are clicked in
		 * quick succession — only the newest one may touch the state.
		 *
		 * @param candidateId
		 * @param token
		 */
		isCurrentRequest(candidateId, token) {
			return this.candidateId === candidateId && this.requestToken === token
		},
		/**
		 * Never leaves the sidebar hanging on "Loading …": a candidate can
		 * vanish or become invisible (deleted, unassigned) between the click
		 * and the request, and that has to be visible, not silent.
		 */
		async reload() {
			const candidateId = this.candidateId
			if (candidateId === null) {
				return
			}
			const token = ++this.requestToken
			this.loading = true
			try {
				const { data } = await api.getCandidate(candidateId)
				if (this.isCurrentRequest(candidateId, token)) {
					this.candidate = data
				}
			} catch (error) {
				if (!this.isCurrentRequest(candidateId, token)) {
					return // a newer candidate is on screen; this failure is stale
				}
				const status = error?.response?.status
				showError(status === 403
					? t('recruiting', 'You no longer have access to this candidate.')
					: errorMessage(error, t('recruiting', 'This candidate could not be loaded — it may have been deleted.')))
				this.close()
				this.version++
			} finally {
				if (this.requestToken === token) {
					this.loading = false
				}
			}
		},
		close() {
			this.candidateId = null
			this.candidate = null
		},
		touch() {
			this.version++
		},
	},
})
