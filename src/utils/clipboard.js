/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

/**
 * Copy text to the clipboard.
 *
 * navigator.clipboard only exists in secure contexts, so on plain-HTTP
 * instances (still common for internal servers) it is undefined — fall back
 * to a hidden textarea, and as a last resort show the link so it can at
 * least be copied by hand.
 *
 * @param {string} text the text to copy
 * @param {string} copied the confirmation shown after copying
 * @return {Promise<boolean>} whether copying worked
 */
export async function copyToClipboard(text, copied = t('recruiting', 'Link copied')) {
	try {
		if (navigator.clipboard?.writeText) {
			await navigator.clipboard.writeText(text)
			showSuccess(copied)
			return true
		}
	} catch {
		// fall through to the legacy path
	}

	try {
		const area = document.createElement('textarea')
		area.value = text
		area.setAttribute('readonly', '')
		area.style.position = 'fixed'
		area.style.opacity = '0'
		document.body.appendChild(area)
		area.select()
		const ok = document.execCommand('copy')
		document.body.removeChild(area)
		if (ok) {
			showSuccess(copied)
			return true
		}
	} catch {
		// reported below
	}

	showError(t('recruiting', 'Could not copy automatically: {link}', { link: text }))
	return false
}
