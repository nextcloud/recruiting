<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="candidate-redirect">
		<NcLoadingIcon v-if="!error" :size="44" />
		<NcEmptyContent v-else :name="t('recruiting', 'Candidate not found')" :description="t('recruiting', 'The candidate may have been deleted, or you have no access.')">
			<template #icon>
				<AccountQuestionOutline />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AccountQuestionOutline from 'vue-material-design-icons/AccountQuestionOutline.vue'
import api from '../api.js'
import { useSidebarStore } from '../store.js'

/**
 * Deep-link target used by notifications, activity and unified search:
 * looks the candidate up, navigates to its context and opens the sidebar.
 */
export default {
	name: 'CandidateRedirect',
	components: { AccountQuestionOutline, NcEmptyContent, NcLoadingIcon },
	props: {
		id: { type: String, required: true },
	},
	setup() {
		return { sidebar: useSidebarStore() }
	},
	data() {
		return { error: false }
	},
	async created() {
		try {
			const { data } = await api.getCandidate(this.id)
			const target = data.openingId !== null
				? { name: 'opening', params: { id: String(data.openingId) } }
				: { name: 'triage' }
			await this.$router.replace(target)
			this.sidebar.open(data.id)
		} catch {
			this.error = true
		}
	},
}
</script>

<style scoped>
.candidate-redirect {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}
</style>
