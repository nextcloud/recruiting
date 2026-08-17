<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppSidebar
		:name="candidate?.displayName ?? t('recruiting', 'Loading …')"
		:subname="subname"
		@close="sidebar.close()">
		<template v-if="candidate" #description>
			<div class="candidate-sidebar__badges">
				<span class="candidate-sidebar__stage" :class="'candidate-sidebar__stage--' + candidate.stage">
					{{ stageLabel(candidate.stage) }}
				</span>
				<span v-if="candidate.rejectionReason" class="candidate-sidebar__reason">
					{{ reasonLabel(candidate.rejectionReason) }}
				</span>
			</div>
		</template>

		<template v-if="candidate && candidate.permissions.manage" #secondary-actions>
			<NcActionButton
				v-if="!isTerminal"
				:closeAfterClick="true"
				:title="t('recruiting', 'Finds free slots in the interviewers’ calendars; the candidate picks one on a public page')"
				@click="showSchedule = true">
				<template #icon>
					<CalendarPlus :size="20" />
				</template>
				{{ t('recruiting', 'Book interview') }}
			</NcActionButton>
			<NcActionSeparator />
			<NcActionButton
				v-for="stage in stageTargets"
				:key="stage"
				:closeAfterClick="true"
				@click="moveTo(stage)">
				<template #icon>
					<ArrowRightThin :size="20" />
				</template>
				{{ t('recruiting', 'Move to "{stage}"', { stage: stageLabel(stage) }) }}
			</NcActionButton>
			<template v-if="session.isRecruiter">
				<NcActionSeparator />
				<NcActionLink
					:href="exportHref"
					:download="true"
					:title="t('recruiting', 'Downloads a ZIP with all of this candidate’s data (for data-access requests) — profile, correspondence, interviews, offers and documents. Reviewer identities are not included.')">
					<template #icon>
						<DownloadOutline :size="20" />
					</template>
					{{ t('recruiting', 'Export data (GDPR)') }}
				</NcActionLink>
				<NcActionButton :closeAfterClick="true" @click="deleteCandidate">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					{{ t('recruiting', 'Delete permanently (GDPR)') }}
				</NcActionButton>
			</template>
		</template>

		<NcEmptyContent v-if="sidebar.loading && !candidate" :name="t('recruiting', 'Loading …')">
			<template #icon>
				<NcLoadingIcon />
			</template>
		</NcEmptyContent>

		<template v-if="candidate">
			<NcAppSidebarTab id="profile" :name="t('recruiting', 'Profile')" :order="1">
				<template #icon>
					<AccountOutline :size="20" />
				</template>
				<ProfileTab :candidate="candidate" @reject="showReject = true" />
			</NcAppSidebarTab>

			<NcAppSidebarTab id="screening" :name="t('recruiting', 'Votes')" :order="2">
				<template #icon>
					<VoteOutline :size="20" />
				</template>
				<ScreeningTab :candidate="candidate" />
			</NcAppSidebarTab>

			<NcAppSidebarTab id="interviews" :name="t('recruiting', 'Interviews')" :order="3">
				<template #icon>
					<CalendarClock :size="20" />
				</template>
				<InterviewsTab :candidate="candidate" @schedule="showSchedule = true" />
			</NcAppSidebarTab>

			<NcAppSidebarTab
				v-if="candidate.permissions.offers"
				id="offer"
				:name="t('recruiting', 'Offer')"
				:order="4">
				<template #icon>
					<HandshakeOutline :size="20" />
				</template>
				<OfferTab :candidate="candidate" />
			</NcAppSidebarTab>

			<NcAppSidebarTab id="activity" :name="t('recruiting', 'Log')" :order="5">
				<template #icon>
					<LightningBoltOutline :size="20" />
				</template>
				<ActivityTab :candidate="candidate" />
			</NcAppSidebarTab>
		</template>

		<ScheduleInterviewModal
			v-if="showSchedule && candidate"
			:candidate="candidate"
			@close="showSchedule = false"
			@saved="onMutated" />
		<RejectModal
			v-if="showReject && candidate"
			:candidate="candidate"
			@close="showReject = false"
			@rejected="onMutated" />
	</NcAppSidebar>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import ArrowRightThin from 'vue-material-design-icons/ArrowRightThin.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import DownloadOutline from 'vue-material-design-icons/DownloadOutline.vue'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import LightningBoltOutline from 'vue-material-design-icons/LightningBoltOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import VoteOutline from 'vue-material-design-icons/VoteOutline.vue'
import RejectModal from './RejectModal.vue'
import ScheduleInterviewModal from './ScheduleInterviewModal.vue'
import ActivityTab from './sidebar/ActivityTab.vue'
import InterviewsTab from './sidebar/InterviewsTab.vue'
import OfferTab from './sidebar/OfferTab.vue'
import ProfileTab from './sidebar/ProfileTab.vue'
import ScreeningTab from './sidebar/ScreeningTab.vue'
import api, { errorMessage } from '../api.js'
import { useSessionStore, useSidebarStore } from '../store.js'
import { confirmDestructive } from '../utils/confirm.js'
import { reasonLabel, stageLabel } from '../utils/format.js'

export default {
	name: 'CandidateSidebar',
	components: {
		AccountOutline,
		ActivityTab,
		ArrowRightThin,
		CalendarClock,
		CalendarPlus,
		DownloadOutline,
		HandshakeOutline,
		InterviewsTab,
		LightningBoltOutline,
		OfferTab,
		NcActionButton,
		NcActionLink,
		NcActionSeparator,
		NcAppSidebar,
		NcAppSidebarTab,
		NcEmptyContent,
		NcLoadingIcon,
		ProfileTab,
		RejectModal,
		ScheduleInterviewModal,
		ScreeningTab,
		TrashCanOutline,
		VoteOutline,
	},

	setup() {
		return {
			sidebar: useSidebarStore(),
			session: useSessionStore(),
		}
	},

	data() {
		return {
			showSchedule: false,
			showReject: false,
		}
	},

	computed: {
		candidate() {
			return this.sidebar.candidate
		},

		exportHref() {
			return this.candidate ? api.exportUrl(this.candidate.id) : '#'
		},

		subname() {
			return this.candidate?.openingTitle ?? this.t('recruiting', 'Triage inbox')
		},

		isTerminal() {
			return this.session.isTerminalStage(this.candidate?.stage)
		},

		stageTargets() {
			if (!this.candidate || this.candidate.openingId === null) {
				return []
			}
			return this.session.stages
				.concat(['hired', 'withdrawn'])
				.filter((stage) => stage !== this.candidate.stage)
		},
	},

	methods: {
		reasonLabel,
		stageLabel,
		async moveTo(stage) {
			try {
				await api.setStage(this.candidate.id, stage)
				this.onMutated()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not move the candidate')))
			}
		},

		async deleteCandidate() {
			if (!await confirmDestructive(
				this.t('recruiting', 'Delete {name} permanently?', { name: this.candidate.displayName }),
				this.t('recruiting', 'All documents, votes, comments and interviews are removed. This cannot be undone.'),
				this.t('recruiting', 'Delete permanently'),
			)) {
				return
			}
			try {
				await api.deleteCandidate(this.candidate.id)
				showSuccess(this.t('recruiting', 'Candidate deleted'))
				this.sidebar.touch()
				this.sidebar.close()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not delete the candidate')))
			}
		},

		onMutated() {
			this.showSchedule = false
			this.showReject = false
			this.sidebar.reload()
			this.sidebar.touch()
		},
	},
}
</script>

<style scoped lang="scss">
// NcAppSidebar lays the tab buttons out as equal-width columns
// (`.app-sidebar-tabs__tab { flex: 1 1 1px }`) in a nav that cannot scroll, so
// every label is ellipsized down to the width of the shortest one. Give each
// button its label as the flex basis instead: they still share the free space
// so the strip is filled, but they never shrink below their label — a narrow
// sidebar or a long-word translation scrolls rather than truncates.
// The parent selectors are not decoration: they outweigh the library's own
// scoped rules, which would otherwise win on source order alone.
:deep(.app-sidebar-tabs > .app-sidebar-tabs__nav) {
	justify-content: flex-start;
	overflow-x: auto;
	scrollbar-width: none;

	&::-webkit-scrollbar {
		display: none;
	}
}

:deep(.app-sidebar-tabs__nav > .app-sidebar-tabs__tab) {
	flex: 1 0 auto;
}

// The label span (second child of the tab button) is `overflow: hidden` for an
// ellipsis it no longer needs, over a line box too short for a descender — so
// the tail of a "g" or "y" ("Log", "Activity") gets sliced off. Give it a line
// box that fits the whole glyph and let the ink out of the box.
:deep(.app-sidebar-tabs__nav > .app-sidebar-tabs__tab > span:last-child) {
	overflow: visible;
	line-height: 1.4;
}

.candidate-sidebar__badges {
	display: flex;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
}

.candidate-sidebar__stage {
	border-radius: var(--border-radius-pill);
	padding: 2px 10px;
	font-size: 0.85em;
	background-color: var(--color-background-hover);

	&--hired {
		background-color: var(--color-success-hover, var(--color-background-hover));
	}

	&--offer,
	&--interview {
		background-color: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
	}
}

.candidate-sidebar__reason {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	align-self: center;
}
</style>
