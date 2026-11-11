<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="today-view">
		<h2>{{ t('recruiting', 'Today') }}</h2>

		<NcLoadingIcon v-if="loading" :size="44" class="today-view__loading" />

		<NcEmptyContent
			v-else-if="today && today.openingCount === 0 && nothingWaiting"
			:name="t('recruiting', 'Welcome to Recruiting')"
			:description="session.isRecruiter
				? t('recruiting', 'Create your first job opening to start collecting applications.')
				: t('recruiting', 'You are not part of a hiring team yet. A recruiter can add you to an opening.')">
			<template #icon>
				<BriefcaseOutline />
			</template>
			<template #action>
				<NcButton v-if="session.isRecruiter" variant="primary" @click="createOpening">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('recruiting', 'Create an opening') }}
				</NcButton>
				<NcButton v-else-if="session.referralsEnabled" :to="{ name: 'referrals' }">
					{{ t('recruiting', 'Refer someone') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else-if="today">
			<template v-if="today.isRecruiter">
				<NcNoteCard v-if="today.mailbox?.status?.error" type="warning">
					{{ t('recruiting', 'The last mailbox check failed {when}: {error}', {
						when: fromNow(today.mailbox.status.ranAt),
						error: today.mailbox.status.error,
					}) }}
				</NcNoteCard>
				<p v-else-if="today.mailbox?.enabled && today.mailbox?.status" class="today-view__heartbeat">
					{{ t('recruiting', 'Mailbox checked {when} — {fetched} mails, {created} new', {
						when: fromNow(today.mailbox.status.ranAt),
						fetched: today.mailbox.status.fetched,
						created: today.mailbox.status.created,
					}) }}
				</p>
				<router-link v-if="today.triageCount > 0" :to="{ name: 'triage' }" class="today-view__triage">
					<InboxArrowDown :size="20" />
					{{ n('recruiting', '%n application waits in the triage inbox', '%n applications wait in the triage inbox', today.triageCount) }}
				</router-link>
			</template>

			<NcEmptyContent
				v-if="nothingWaiting"
				:name="t('recruiting', 'Nothing is waiting for you')"
				:description="t('recruiting', 'No votes, interviews or approvals are due, and nothing is stuck. Your openings are in the navigation.')">
				<template #icon>
					<CheckCircleOutline />
				</template>
			</NcEmptyContent>

			<section v-if="today.approvals.length > 0" class="today-view__section">
				<h3>{{ t('recruiting', 'Offers waiting for your approval') }}</h3>
				<ul class="today-view__list">
					<li v-for="approval in today.approvals" :key="approval.offerId">
						<div
							class="today-view__item"
							role="button"
							tabindex="0"
							@click="open(approval.candidate)"
							@keydown.enter.prevent="open(approval.candidate)">
							<strong>{{ approval.candidate.displayName }}</strong>
							<span class="today-view__meta">
								{{ t('recruiting', '{job} · requested by {name}', { job: approval.jobTitle, name: approval.requestedBy }) }}
							</span>
						</div>
					</li>
				</ul>
			</section>

			<section v-if="today.interviews.length > 0" class="today-view__section">
				<h3>{{ t('recruiting', 'Your interviews this week') }}</h3>
				<ul class="today-view__list">
					<li v-for="interview in today.interviews" :key="interview.id">
						<div
							class="today-view__item today-view__item--interview"
							role="button"
							tabindex="0"
							@click="open(interview.candidate)"
							@keydown.enter.prevent="open(interview.candidate)">
							<span class="today-view__when" :class="{ 'today-view__when--today': isToday(interview.startAt) }">
								<strong>{{ isToday(interview.startAt) ? t('recruiting', 'Today') : formatDate(interview.startAt) }}</strong>
								<span>{{ formatTime(interview.startAt) }}</span>
							</span>
							<span class="today-view__what">
								<strong>{{ interview.candidate.displayName }}</strong>
								<span class="today-view__meta">
									{{ interview.title }}
									<template v-if="interview.candidate.openingTitle">
										· {{ interview.candidate.openingTitle }}
									</template>
								</span>
							</span>
						</div>
					</li>
				</ul>
			</section>

			<section v-if="today.votesOwed.length > 0" class="today-view__section">
				<h3>{{ n('recruiting', '%n candidate waits for your vote', '%n candidates wait for your vote', today.votesOwedCount) }}</h3>
				<ul class="today-view__list">
					<li v-for="candidate in today.votesOwed" :key="candidate.id">
						<div
							class="today-view__item"
							role="button"
							tabindex="0"
							@click="open(candidate)"
							@keydown.enter.prevent="open(candidate)">
							<strong>{{ candidate.displayName }}</strong>
							<span class="today-view__meta">{{ candidate.openingTitle }}</span>
						</div>
					</li>
				</ul>
				<router-link v-if="today.votesOwedCount > today.votesOwed.length" :to="{ name: 'myReviews' }">
					{{ t('recruiting', 'Show all in “My reviews”') }}
				</router-link>
			</section>

			<section v-if="today.stuck.length > 0" class="today-view__section">
				<h3>{{ n('recruiting', '%n candidate is stuck in your openings', '%n candidates are stuck in your openings', today.stuckCount) }}</h3>
				<ul class="today-view__list">
					<li v-for="candidate in today.stuck" :key="candidate.id">
						<div
							class="today-view__item"
							role="button"
							tabindex="0"
							@click="open(candidate)"
							@keydown.enter.prevent="open(candidate)">
							<strong>{{ candidate.displayName }}</strong>
							<span class="today-view__meta">
								{{ candidate.openingTitle }} · {{ stageLabel(candidate.stage) }} · {{ candidate.attention.map(attentionLabel).join(', ') }}
							</span>
						</div>
					</li>
				</ul>
				<p v-if="today.stuckCount > today.stuck.length" class="today-view__meta">
					{{ t('recruiting', 'The “Stuck” filter on each opening’s board shows the rest.') }}
				</p>
			</section>
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import InboxArrowDown from 'vue-material-design-icons/InboxArrowDown.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import api, { errorMessage } from '../api.js'
import { useSessionStore, useSidebarStore } from '../store.js'
import { attentionLabel, formatDate, formatTime, fromNow, stageLabel } from '../utils/format.js'

/**
 * The start page: what is waiting for the user across all openings.
 */
export default {
	name: 'TodayView',
	components: {
		BriefcaseOutline,
		CheckCircleOutline,
		InboxArrowDown,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		Plus,
	},

	inject: ['createOpening'],

	setup() {
		return {
			session: useSessionStore(),
			sidebar: useSidebarStore(),
		}
	},

	data() {
		return {
			today: null,
			loading: true,
		}
	},

	computed: {
		nothingWaiting() {
			const today = this.today
			return today !== null
				&& today.approvals.length === 0
				&& today.interviews.length === 0
				&& today.votesOwed.length === 0
				&& today.stuck.length === 0
				&& !(today.triageCount > 0)
		},
	},

	watch: {
		'sidebar.version': function() {
			this.load(true)
		},
	},

	created() {
		this.load()
	},

	methods: {
		attentionLabel,
		formatDate,
		formatTime,
		fromNow,
		stageLabel,
		isToday(iso) {
			return moment(iso).isSame(moment(), 'day')
		},

		open(candidate) {
			this.sidebar.open(candidate.id)
		},

		async load(silent = false) {
			if (!silent) {
				this.loading = true
			}
			try {
				const { data } = await api.getToday()
				this.today = data
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load your overview')))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.today-view {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	max-width: 900px;

	h2,
	h3 {
		margin: 0;
	}

	&__loading {
		margin-top: 15vh;
	}

	&__heartbeat,
	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		margin: 0;
	}

	&__triage {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		font-weight: 600;
	}

	&__section {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__item {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 3);
		cursor: pointer;

		&:hover,
		&:focus-visible {
			border-color: var(--color-primary-element);
		}

		&--interview {
			flex-direction: row;
			align-items: center;
			gap: calc(var(--default-grid-baseline) * 4);
		}
	}

	&__when {
		display: flex;
		flex-direction: column;
		min-width: 110px;
		text-align: center;
		background-color: var(--color-background-hover);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 2);

		&--today {
			background-color: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}
	}

	&__what {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}
}
</style>
