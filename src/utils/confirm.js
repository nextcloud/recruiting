/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getDialogBuilder } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

/**
 * Ask before a destructive action — a themed, translatable NcDialog instead
 * of the browser's native confirm().
 *
 * @param {string} title short question, e.g. "Delete document?"
 * @param {string} text what will happen, who is notified, what is irreversible
 * @param {string} confirmLabel label of the destructive button
 * @return {Promise<boolean>} true when the user confirmed
 */
export async function confirmDestructive(title, text, confirmLabel) {
	let confirmed = false
	await getDialogBuilder(title)
		.setText(text)
		.addButton({
			label: t('recruiting', 'Cancel'),
			callback: () => {},
		})
		.addButton({
			label: confirmLabel,
			type: 'error',
			callback: () => {
				confirmed = true
			},
		})
		.build()
		.show()
	return confirmed
}
