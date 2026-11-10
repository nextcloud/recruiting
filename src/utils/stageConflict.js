/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { showWarning } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { stageLabel } from './format.js'

/**
 * A stage move was refused because someone else moved the candidate first
 * (HTTP 409). Tells the user who went where; the caller reloads.
 *
 * @param {Error} error axios error
 * @param {string} fallbackName name to show when the response carries none
 * @return {boolean} true when the error was such a conflict
 */
export function handleStageConflict(error, fallbackName = '') {
	if (!isStageConflict(error)) {
		return false
	}
	const data = error.response.data ?? {}
	showWarning(t('recruiting', '{name} was moved to "{stage}" by someone else', {
		name: data.displayName || fallbackName,
		stage: stageLabel(data.stage),
	}))
	return true
}

/**
 * @param {Error} error axios error
 * @return {boolean} true for a refused stage move (HTTP 409)
 */
export function isStageConflict(error) {
	return error?.response?.status === 409
}
