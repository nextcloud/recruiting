<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcButton
		v-if="session.aiAvailable"
		variant="tertiary"
		:disabled="working"
		:title="t('recruiting', 'AI-generated — always review before using')"
		@click="run">
		<template #icon>
			<NcLoadingIcon v-if="working" :size="20" />
			<CreationOutline v-else :size="20" />
		</template>
		{{ label }}
	</NcButton>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CreationOutline from 'vue-material-design-icons/CreationOutline.vue'
import api, { errorMessage } from '../api.js'
import { useSessionStore } from '../store.js'

/**
 * A "do it with AI" button: schedules a TaskProcessing task and polls
 * until the result is in, then emits it. Renders nothing when no AI
 * provider is configured.
 */
export default {
	name: 'AiAssist',
	components: { CreationOutline, NcButton, NcLoadingIcon },
	props: {
		candidateId: { type: Number, required: true },
		action: { type: String, required: true },
		label: { type: String, required: true },
	},
	emits: ['result'],
	setup() {
		return { session: useSessionStore() }
	},
	data() {
		return { working: false }
	},
	beforeUnmount() {
		this.working = false
	},
	methods: {
		async run() {
			this.working = true
			try {
				const { data } = await api.aiRequest(this.candidateId, this.action)
				const started = Date.now()
				// Back off while waiting: 1s, 2s, … capped at 5s — most tasks
				// return within seconds, long ones should not hammer the server
				let delay = 1000
				while (this.working) {
					await new Promise((resolve) => setTimeout(resolve, delay))
					delay = Math.min(delay + 1000, 5000)
					const { data: task } = await api.aiTask(data.taskId)
					if (task.status === 'done') {
						this.$emit('result', task.output)
						return
					}
					if (task.status === 'failed') {
						showError(this.t('recruiting', 'The AI request failed — please try again or write it yourself.'))
						return
					}
					if (Date.now() - started > 120000) {
						showError(this.t('recruiting', 'The AI request is taking too long — it may still finish in the background.'))
						return
					}
				}
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'The AI request failed')))
			} finally {
				this.working = false
			}
		},
	},
}
</script>
