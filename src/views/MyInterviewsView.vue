<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="my-interviews">
		<h2>{{ t('recruiting', 'My interviews') }}</h2>
		<p class="my-interviews__hint">
			{{ t('recruiting', 'Interviews where you are on the panel. “Pending” means the candidate has not picked a time slot yet; confirmed interviews are also in your calendar. Click an entry to open the candidate.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="44" class="my-interviews__loading" />
		<NcEmptyContent
			v-else-if="interviews.length === 0"
			:name="t('recruiting', 'No upcoming interviews')">
			<template #icon>
				<CalendarClock />
			</template>
		</NcEmptyContent>

		<ul v-else class="my-interviews__list">
			<li
				v-for="interview in interviews"
				:key="interview.id"
				class="my-interviews__item"
				role="button"
				tabindex="0"
				@click="sidebar.open(interview.candidate.id)"
				@keydown.enter.prevent="sidebar.open(interview.candidate.id)">
				<div class="my-interviews__when" :class="{ 'my-interviews__when--pending': !interview.startAt }">
					<template v-if="interview.startAt">
						<strong>{{ formatDate(interview.startAt) }}</strong>
						<span>{{ formatTime(interview.startAt) }}</span>
					</template>
					<template v-else>
						<strong>{{ t('recruiting', 'Pending') }}</strong>
						<span>{{ t('recruiting', 'awaiting candidate') }}</span>
					</template>
				</div>
				<div class="my-interviews__what">
					<strong>{{ interview.candidate.displayName }}</strong>
					<span class="my-interviews__meta">
						{{ interview.title }}
						<template v-if="interview.candidate.openingTitle">
							· {{ interview.candidate.openingTitle }}
						</template>
					</span>
				</div>
				<a
					v-if="interview.talkLink"
					class="my-interviews__talk button primary"
					:href="interview.talkLink"
					target="_blank"
					rel="noopener"
					@click.stop>
					🎥 {{ t('recruiting', 'Join call') }}
				</a>
			</li>
		</ul>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import api, { errorMessage } from '../api.js'
import { useSidebarStore } from '../store.js'
import { formatDate, formatTime } from '../utils/format.js'

export default {
	name: 'MyInterviewsView',
	components: { CalendarClock, NcEmptyContent, NcLoadingIcon },
	setup() {
		return { sidebar: useSidebarStore() }
	},

	data() {
		return {
			interviews: [],
			loading: true,
		}
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
		formatDate,
		formatTime,
		async load(silent = false) {
			if (!silent) {
				this.loading = true
			}
			try {
				const { data } = await api.getMyInterviews()
				this.interviews = data
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load your interviews')))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.my-interviews {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	max-width: 900px;

	h2 {
		margin: 0;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	&__loading {
		margin-top: 15vh;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__item {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 4);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 3);
		cursor: pointer;

		&:hover {
			border-color: var(--color-primary-element);
		}
	}

	&__when {
		display: flex;
		flex-direction: column;
		min-width: 110px;
		text-align: center;
		background-color: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 2);

		&--pending {
			background-color: var(--color-background-hover);
			color: var(--color-text-maxcontrast);
		}
	}

	&__what {
		display: flex;
		flex-direction: column;
		flex: 1;
		min-width: 0;
	}

	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__talk {
		text-decoration: none;
	}
}
</style>
