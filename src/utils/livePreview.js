/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import api from '../api.js'

/**
 * Mixin: a live preview of a mail text with placeholders, rendered on the
 * server for one candidate while the text is edited.
 *
 * The component provides `subject`, `body`, `loadingTemplate` and a
 * `previewCandidateId` (null: no preview), and calls `setPreview()` with
 * the rendered form of a freshly loaded template.
 *
 * @param {string} type mail template type
 * @return {object}
 */
export default function livePreview(type) {
	return {
		data() {
			return {
				preview: { subject: '', body: '' },
				loadingPreview: false,
				previewTimer: null,
				previewedText: null,
			}
		},

		watch: {
			subject() {
				this.schedulePreview()
			},

			body() {
				this.schedulePreview()
			},
		},

		beforeUnmount() {
			clearTimeout(this.previewTimer)
		},

		methods: {
			setPreview(rendered) {
				this.preview = { subject: rendered.subject, body: rendered.body }
				this.previewedText = this.subject + '\0' + this.body
			},

			schedulePreview() {
				if (this.loadingTemplate || this.previewCandidateId === null || this.previewedText === this.subject + '\0' + this.body) {
					return
				}
				clearTimeout(this.previewTimer)
				this.previewTimer = setTimeout(this.refreshPreview, 500)
			},

			async refreshPreview() {
				this.loadingPreview = true
				try {
					const text = this.subject + '\0' + this.body
					const { data } = await api.previewMailText(this.previewCandidateId, type, this.subject, this.body)
					this.preview = { subject: data.subject, body: data.body }
					this.previewedText = text
				} catch {
					// the last preview stays; sending renders on the server anyway
				} finally {
					this.loadingPreview = false
				}
			},
		},
	}
}
