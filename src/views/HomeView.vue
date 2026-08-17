<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="home-view">
		<NcLoadingIcon v-if="openings.loading" :size="44" />
		<NcEmptyContent
			v-else-if="openings.openings.length === 0"
			:name="t('recruiting', 'Welcome to Recruiting')"
			:description="emptyDescription">
			<template #icon>
				<BriefcaseOutline />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import { useOpeningsStore, useSessionStore } from '../store.js'

export default {
	name: 'HomeView',
	components: { BriefcaseOutline, NcEmptyContent, NcLoadingIcon },
	setup() {
		return {
			openings: useOpeningsStore(),
			session: useSessionStore(),
		}
	},
	computed: {
		emptyDescription() {
			return this.session.isRecruiter
				? this.t('recruiting', 'Create your first job opening to start collecting applications.')
				: this.t('recruiting', 'You are not part of a hiring team yet. A recruiter can add you to an opening.')
		},
	},
	watch: {
		'openings.loaded': {
			immediate: true,
			handler() {
				// Land on the first opening the user can see
				if (this.openings.loaded && this.openings.open.length > 0) {
					this.$router.replace({ name: 'opening', params: { id: String(this.openings.open[0].id) } })
				}
			},
		},
	},
}
</script>

<style scoped>
.home-view {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}
</style>
