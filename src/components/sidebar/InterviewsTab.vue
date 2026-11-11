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
			<div
				v-for="interview in candidate.interviews"
				:key="interview.id"
				class="interview"
				:class="'interview--' + interview.status">
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
					<li
						v-for="slot in interview.slots"
						:key="slot.id"
						:class="{ 'interview__slot--unavailable': slot.unavailable }"
						:title="slot.unavailable ? t('recruiting', 'Somebody on the panel was busy by the time the candidate picked this time') : null">
						{{ formatDateTime(slot.startAt) }}
						<span v-if="slot.unavailable">({{ t('recruiting', 'no longer available') }})</span>
					</li>
				</ul>

				<section v-if="interview.feedbackOpen" class="interview__feedback">
					<div class="interview__feedback-head">
						<strong>{{ t('recruiting', 'Feedback') }}</strong>
						<span class="interview__feedback-count">
							{{ t('recruiting', '{given} of {total} interviewers', { given: interview.feedback.length, total: interview.attendeeCount }) }}
						</span>
					</div>
					<NcNoteCard v-if="interview.feedback.some((entry) => entry.verdict === null)" type="info">
						{{ t('recruiting', 'The feedback of others becomes visible once you gave your own — this keeps everyone\'s opinion unbiased.') }}
					</NcNoteCard>
					<ul v-if="interview.feedback.length > 0" class="interview__feedback-list">
						<li v-for="entry in interview.feedback" :key="entry.uid" class="interview__feedback-entry">
							<NcAvatar :user="entry.uid" :displayName="entry.displayName" :size="24" />
							<div class="interview__feedback-body">
								<div>
									<strong>{{ entry.displayName }}</strong>
									<span v-if="entry.verdict" class="interview__verdict">
										{{ voteMeta(entry.verdict).emoji }} {{ voteMeta(entry.verdict).label }}
									</span>
									<span v-else class="interview__verdict interview__verdict--hidden">
										{{ t('recruiting', 'hidden') }}
									</span>
								</div>
								<p v-if="entry.notes" class="interview__feedback-notes">
									{{ entry.notes }}
								</p>
							</div>
						</li>
					</ul>

					<template v-if="interview.canGiveFeedback">
						<div v-if="feedbackFor === interview.id" class="interview__feedback-form">
							<div class="interview__verdict-buttons">
								<NcButton
									v-for="option in verdictOptions"
									:key="option.id"
									:variant="verdict === option.id ? 'primary' : 'secondary'"
									@click="verdict = option.id">
									{{ option.emoji }} {{ option.label }}
								</NcButton>
							</div>
							<NcTextArea
								v-model="notes"
								:label="t('recruiting', 'Notes')"
								:placeholder="verdict === 'no'
									? t('recruiting', 'Required: why is your verdict no?')
									: t('recruiting', 'What stood out in this interview?')"
								rows="3"
								resize="vertical" />
							<div class="interview__actions">
								<NcButton variant="tertiary" @click="feedbackFor = null">
									{{ t('recruiting', 'Cancel') }}
								</NcButton>
								<NcButton
									variant="primary"
									:disabled="!verdict || (verdict === 'no' && !notes.trim()) || saving"
									@click="submitFeedback(interview)">
									{{ t('recruiting', 'Save feedback') }}
								</NcButton>
							</div>
						</div>
						<NcButton v-else @click="openFeedback(interview)">
							<template #icon>
								<CommentCheckOutline :size="18" />
							</template>
							{{ interview.myFeedback ? t('recruiting', 'Edit my feedback') : t('recruiting', 'Give feedback') }}
						</NcButton>
					</template>
				</section>

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
					<NcButton v-if="!isTerminal" variant="tertiary" @click="$emit('reschedule', interview)">
						<template #icon>
							<CalendarEdit :size="18" />
						</template>
						{{ t('recruiting', 'Propose other times') }}
					</NcButton>
					<NcButton variant="tertiary" @click="cancelling = interview">
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

		<CancelInterviewModal
			v-if="cancelling"
			:candidate="candidate"
			:interview="cancelling"
			@close="cancelling = null"
			@cancelled="onCancelled" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CalendarEdit from 'vue-material-design-icons/CalendarEdit.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import CommentCheckOutline from 'vue-material-design-icons/CommentCheckOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import AiAssist from '../AiAssist.vue'
import CancelInterviewModal from '../CancelInterviewModal.vue'
import api, { errorMessage } from '../../api.js'
import { useSessionStore, useSidebarStore } from '../../store.js'
import { copyToClipboard } from '../../utils/clipboard.js'
import { formatDateTime, interviewStatusLabel, voteMeta } from '../../utils/format.js'

export default {
	name: 'InterviewsTab',
	components: {
		AiAssist,
		CalendarClock,
		CalendarEdit,
		CancelInterviewModal,
		CloseCircleOutline,
		CommentCheckOutline,
		ContentCopy,
		NcAvatar,
		NcButton,
		NcEmptyContent,
		NcNoteCard,
		NcTextArea,
		Plus,
	},

	props: {
		candidate: { type: Object, required: true },
	},

	emits: ['schedule', 'reschedule'],
	setup() {
		return { sidebar: useSidebarStore(), session: useSessionStore() }
	},

	data() {
		return {
			aiQuestions: '',
			cancelling: null,
			feedbackFor: null,
			verdict: null,
			notes: '',
			saving: false,
		}
	},

	computed: {
		isTerminal() {
			return this.session.isTerminalStage(this.candidate.stage)
		},

		verdictOptions() {
			return ['yes', 'maybe', 'no'].map((value) => ({ id: value, ...voteMeta(value) }))
		},
	},

	methods: {
		formatDateTime,
		statusLabel: interviewStatusLabel,
		copyLink(interview) {
			copyToClipboard(interview.publicUrl)
		},

		voteMeta,
		onCancelled() {
			this.cancelling = null
			this.sidebar.reload()
			this.sidebar.touch()
		},

		openFeedback(interview) {
			this.feedbackFor = interview.id
			this.verdict = interview.myFeedback?.verdict ?? null
			this.notes = interview.myFeedback?.notes ?? ''
		},

		async submitFeedback(interview) {
			this.saving = true
			try {
				await api.submitFeedback(interview.id, this.verdict, this.notes)
				showSuccess(this.t('recruiting', 'Feedback saved'))
				this.feedbackFor = null
				this.sidebar.reload()
				this.sidebar.touch()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not save the feedback')))
			} finally {
				this.saving = false
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

	&__slot--unavailable {
		text-decoration: line-through;
	}

	&__feedback {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		border-top: 1px solid var(--color-border);
		padding-top: calc(var(--default-grid-baseline) * 2);
	}

	&__feedback-head {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__feedback-count {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__feedback-list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__feedback-entry {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: flex-start;
	}

	&__feedback-body {
		min-width: 0;
	}

	&__verdict {
		margin-inline-start: var(--default-grid-baseline);

		&--hidden {
			color: var(--color-text-maxcontrast);
		}
	}

	&__feedback-notes {
		margin: 0;
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}

	&__feedback-form {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__verdict-buttons {
		display: flex;
		flex-wrap: wrap;
		gap: var(--default-grid-baseline);
	}
}
</style>
