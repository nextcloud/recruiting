/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import AdminSettings from './views/AdminSettings.vue'

const app = createApp(AdminSettings)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#recruiting-admin-settings')
