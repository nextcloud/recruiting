/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { createApp } from 'vue'
import PersonalSettings from './views/PersonalSettings.vue'

const app = createApp(PersonalSettings)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#recruiting-personal-settings')
