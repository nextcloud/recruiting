/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import router from './router.js'

const app = createApp(App)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.use(createPinia())
app.use(router)
app.mount('#recruiting-app')
