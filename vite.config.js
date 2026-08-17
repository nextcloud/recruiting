/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createAppConfig } from '@nextcloud/vite-config'

// Entry names are prefixed with the app id at build time, producing
// js/recruiting-main.mjs, js/recruiting-admin-settings.mjs and
// js/recruiting-public-interview.mjs.
export default createAppConfig({
	main: 'src/main.js',
	'admin-settings': 'src/admin-settings.js',
	'personal-settings': 'src/personal-settings.js',
	'public-interview': 'src/public-interview.js',
}, {
	// Inject component CSS from the JS entries so a single Util::addScript()
	// styles the whole app (also covers lazily-loaded chunk CSS).
	inlineCSS: { relativeCSSInjection: true },
})
