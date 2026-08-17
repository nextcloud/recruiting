<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="interviews-tab">
		<NcEmptyContent
			v-if="candidate.interviews.length === 0"
			:name="t('recruiting', 'No interviews yet')"
			:description="candidate.permissions.manage ? t('recruiting', 'Propose time slots from the interviewers\' calendars — the candidate picks one on a public page.') : ''">
			<template #icon>
				<CalendarClock />
			</template>
			<template v-if="candidate.permissions.manage && !isTerminal" #action>
				<NcButton variant="primary" @click="$emit('schedule')">
					{{ t('recruiting', 'Book interview') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<div v-for="interview in candidate.interviews" :key="interview.id" class="interview" :class="'interview--' + interview.status">
				<div class="interview__head">
					<strong>{{ interview.title }}</strong>
					<span class="interview__status" :class="'interview__status--' + interview.status">
						{{ statusLabel(interview.status) }}
					</span>
				</div>

				<div class="interview__facts">
					<span v-if="interview.startAt">📅 {{ formatDateTime(interview.startAt) }}</span>
					<span v-else>⏳ {{ n('recruiting', '%n slot proposed', '%n slots proposed', interview.slots.length) }}</span>
					<span>🕐 {{ t('recruiting', '{minutes} minutes', { minutes: interview.durationMin }) }}</span>
					<span v-if="interview.isVideo && interview.talkLink">
						🎥 <a :href="interview.talkLink" target="_blank" rel="noopener">{{ t('recruiting', 'Talk room') }}</a>
					</span>
					<span v-else-if="interview.location">📍 {{ interview.location }}</span>
				</div>

				<div class="interview__attendees">
					<NcAvatar
						v-for="attendee in interview.attendees"
						:key="attendee.uid"
						:user="attendee.uid"
						:displayName="attendee.displayName"
						:size="24"
						:title="attendee.displayName" />
				</div>

				<ul v-if="interview.status === 'proposed'" class="interview__slots">
					<li v-for="slot in interview.slots" :key="slot.id">
						{{ formatDateTime(slot.startAt) }}
					</li>
				</ul>

				<div v-if="candidate.permissions.manage && ['proposed', 'confirmed'].includes(interview.status)" class="interview__actions">
					<NcButton
						v-if="interview.status === 'proposed'"
						variant="tertiary"
						:title="t('recruiting', 'The public page where the candidate picks a slot — no account needed. Confirming books the calendar event for everyone.')"
						@click="copyLink(interview)">
						<template #icon>
							<ContentCopy :size="18" />
						</template>
						{{ t('recruiting', 'Copy scheduling link') }}
					</NcButton>
					<NcButton variant="tertiary" @click="cancel(interview)">
						<template #icon>
							<CloseCircleOutline :size="18" />
						</template>
						{{ t('recruiting', 'Cancel interview') }}
					</NcButton>
				</div>
			</div>

			<NcButton v-if="candidate.permissions.manage && !isTerminal" @click="$emit('schedule')">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('recruiting', 'Book another interview') }}
			</NcButton>
		</template>

		<div v-if="aiQuestions" class="interviews-tab__questions">
			<strong>🪄 {{ t('recruiting', 'AI question suggestions — pick what fits') }}</strong>
			<p>{{ aiQuestions }}</p>
		</div>
		<AiAssist
			v-if="candidate.permissions.manage && !isTerminal"
			:candidateId="candidate.id"
			action="questions"
			:label="t('recruiting', 'Suggest interview questions')"
			@result="aiQuestions = $event" />
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import api, { errorMessage } from '../../api.js'
import { confirmDestructive } from '../../utils/confirm.js'
import { useSessionStore, useSidebarStore } from '../../store.js'
import { copyToClipboard } from '../../utils/clipboard.js'
import { formatDateTime, interviewStatusLabel } from '../../utils/format.js'
import AiAssist from '../AiAssist.vue'

export default {
	name: 'InterviewsTab',
	components: {
		AiAssist,
		CalendarClock,
		CloseCircleOutline,
		ContentCopy,
		NcAvatar,
		NcButton,
		NcEmptyContent,
		Plus,
	},
	props: {
		candidate: { type: Object, required: true },
	},
	emits: ['schedule'],
	setup() {
		return { sidebar: useSidebarStore(), session: useSessionStore() }
	},
	data() {
		return { aiQuestions: '' }
	},
	computed: {
		isTerminal() {
			return this.session.isTerminalStage(this.candidate.stage)
		},
	},
	methods: {
		formatDateTime,
		statusLabel: interviewStatusLabel,
		copyLink(interview) {
			copyToClipboard(interview.publicUrl)
		},
		async cancel(interview) {
			if (!await confirmDestructive(
				this.t('recruiting', 'Cancel "{title}"?', { title: interview.title }),
				this.t('recruiting', 'Interviewers are notified and the calendar event is cancelled.'),
				this.t('recruiting', 'Cancel interview'),
			)) {
				return
			}
			try {
				await api.cancelInterview(interview.id)
				this.sidebar.reload()
				this.sidebar.touch()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not cancel the interview')))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.interviews-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding-block: calc(var(--default-grid-baseline) * 2);

	&__questions {
		border: 1px dashed var(--color-border-maxcontrast);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 3);
		font-size: 0.9em;

		p {
			margin: var(--default-grid-baseline) 0 0;
			white-space: pre-wrap;
		}
	}
}

.interview {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&--cancelled {
		opacity: 0.6;
	}

	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__status {
		border-radius: var(--border-radius-pill);
		padding: 2px 10px;
		font-size: 0.85em;
		background-color: var(--color-background-hover);
		white-space: nowrap;

		&--confirmed {
			background-color: var(--color-success-hover, var(--color-background-hover));
		}

		&--proposed {
			background-color: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}
	}

	&__facts {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 3);
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__attendees {
		display: flex;
		gap: 2px;
	}

	&__slots {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		padding-inline-start: calc(var(--default-grid-baseline) * 4);
		list-style: disc;
	}

	&__actions {
		display: flex;
		gap: var(--default-grid-baseline);
		flex-wrap: wrap;
	}
}
</style>
