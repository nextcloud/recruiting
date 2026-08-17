<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent appName="recruiting">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationNew
					v-if="session.isRecruiter"
					:text="t('recruiting', 'New opening')"
					@click="showOpeningModal = true">
					<template #icon>
						<Plus :size="20" />
					</template>
				</NcAppNavigationNew>

				<NcAppNavigationItem
					v-if="session.isRecruiter"
					:name="t('recruiting', 'Triage inbox')"
					:to="{ name: 'triage' }">
					<template #icon>
						<InboxArrowDown :size="20" />
					</template>
					<template v-if="session.triageCount > 0" #counter>
						<NcCounterBubble :count="session.triageCount" type="highlighted" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem :name="t('recruiting', 'My reviews')" :to="{ name: 'myReviews' }">
					<template #icon>
						<VoteOutline :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem :name="t('recruiting', 'My interviews')" :to="{ name: 'myInterviews' }">
					<template #icon>
						<CalendarClock :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					v-if="session.isRecruiter"
					:name="t('recruiting', 'Mail templates')"
					:to="{ name: 'templates' }">
					<template #icon>
						<EmailEditOutline :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					v-if="session.isRecruiter || openings.openings.length > 0"
					:name="t('recruiting', 'Reports')"
					:to="{ name: 'reports' }">
					<template #icon>
						<ChartBoxOutline :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					v-if="session.isRecruiter"
					:name="t('recruiting', 'Talent pool')"
					:to="{ name: 'pool' }">
					<template #icon>
						<AccountHeartOutline :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationCaption v-if="openings.open.length > 0" :name="t('recruiting', 'Openings')" />
				<NcAppNavigationItem
					v-for="opening in openings.open"
					:key="opening.id"
					:name="opening.title"
					:to="{ name: 'opening', params: { id: String(opening.id) } }">
					<template #icon>
						<PauseCircleOutline v-if="opening.status === 'on_hold'" :size="20" />
						<BriefcaseOutline v-else :size="20" />
					</template>
					<template v-if="opening.activeCount > 0" #counter>
						<NcCounterBubble :count="opening.activeCount" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					v-if="openings.closed.length > 0"
					:name="t('recruiting', 'Closed openings')"
					:allowCollapse="true"
					:open="false">
					<template #icon>
						<ArchiveOutline :size="20" />
					</template>
					<template #default>
						<NcAppNavigationItem
							v-for="opening in openings.closed"
							:key="opening.id"
							:name="opening.title"
							:to="{ name: 'opening', params: { id: String(opening.id) } }">
							<template #icon>
								<BriefcaseOffOutline :size="20" />
							</template>
						</NcAppNavigationItem>
					</template>
				</NcAppNavigationItem>
			</template>

			<template #footer>
				<NcAppNavigationItem
					:name="t('recruiting', 'Handbook (PDF)')"
					:href="handbookUrl"
					target="_blank">
					<template #icon>
						<BookOpenOutline :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<router-view />
		</NcAppContent>

		<CandidateSidebar v-if="sidebar.candidateId !== null" />

		<OpeningModal
			v-if="showOpeningModal"
			@close="showOpeningModal = false"
			@saved="onOpeningSaved" />
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import AccountHeartOutline from 'vue-material-design-icons/AccountHeartOutline.vue'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import BookOpenOutline from 'vue-material-design-icons/BookOpenOutline.vue'
import BriefcaseOffOutline from 'vue-material-design-icons/BriefcaseOffOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import EmailEditOutline from 'vue-material-design-icons/EmailEditOutline.vue'
import InboxArrowDown from 'vue-material-design-icons/InboxArrowDown.vue'
import PauseCircleOutline from 'vue-material-design-icons/PauseCircleOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import VoteOutline from 'vue-material-design-icons/VoteOutline.vue'
import { generateUrl } from '@nextcloud/router'
import CandidateSidebar from './components/CandidateSidebar.vue'
import OpeningModal from './components/OpeningModal.vue'
import { useOpeningsStore, useSessionStore, useSidebarStore } from './store.js'

export default {
	name: 'App',
	components: {
		AccountHeartOutline,
		ArchiveOutline,
		BookOpenOutline,
		BriefcaseOffOutline,
		BriefcaseOutline,
		CalendarClock,
		CandidateSidebar,
		ChartBoxOutline,
		EmailEditOutline,
		InboxArrowDown,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationCaption,
		NcAppNavigationItem,
		NcAppNavigationNew,
		NcContent,
		NcCounterBubble,
		OpeningModal,
		PauseCircleOutline,
		Plus,
		VoteOutline,
	},
	setup() {
		return {
			session: useSessionStore(),
			openings: useOpeningsStore(),
			sidebar: useSidebarStore(),
		}
	},
	computed: {
		handbookUrl() {
			return generateUrl('/apps/recruiting/handbook')
		},
	},
	data() {
		return {
			showOpeningModal: false,
		}
	},
	watch: {
		// A candidate belongs to the view it was opened from — carrying the
		// sidebar over to the reports or the talent pool is just confusing.
		$route(to, from) {
			if (to.name !== from.name || to.params.id !== from.params.id) {
				this.sidebar.close()
			}
		},
	},
	created() {
		this.openings.load()
	},
	methods: {
		onOpeningSaved(opening) {
			this.showOpeningModal = false
			this.$router.push({ name: 'opening', params: { id: String(opening.id) } })
		},
	},
}
</script>

<style lang="scss">
// Keep the page titles clear of the floating app-navigation toggle button,
// which overlays the top-left corner of the content area (applies to every view).
.app-recruiting {
	.opening-view__header,
	.triage-view > h2,
	.my-reviews > h2,
	.my-interviews > h2,
	.templates-view > h2,
	.reports-view > h2,
	.pool-view > h2 {
		padding-inline-start: calc(var(--default-clickable-area, 44px) + var(--app-navigation-padding, 4px) * 2);
	}
}
</style>
