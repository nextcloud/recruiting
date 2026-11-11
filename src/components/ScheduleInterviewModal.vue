<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal :name="interview ? t('recruiting', 'Propose other times') : t('recruiting', 'Book interview')" size="normal" @close="$emit('close')">
		<div class="schedule-modal">
			<!-- Step 1: pick interviewers & slots -->
			<template v-if="step === 1">
				<template v-if="interview">
					<h2>{{ t('recruiting', 'Propose other times for "{title}"', { title: interview.title }) }}</h2>
					<NcNoteCard type="info">
						{{ interview.status === 'confirmed'
							? t('recruiting', 'The confirmed time is released and its calendar event removed. {name} picks a new time with the same link as before.', { name: candidate.displayName })
							: t('recruiting', 'The times proposed so far are replaced. {name} picks a new time with the same link as before.', { name: candidate.displayName }) }}
					</NcNoteCard>
				</template>
				<h2 v-else>
					{{ t('recruiting', 'Book an interview with {name}', { name: candidate.displayName }) }}
				</h2>

				<NcTextField
					v-if="!interview"
					v-model="title"
					:label="t('recruiting', 'Interview title')"
					:placeholder="t('recruiting', 'e.g. Technical interview')" />

				<div v-if="!interview" class="schedule-modal__row">
					<!-- TODO: migrate to NcSelectUsers -->
					<!-- eslint-disable @nextcloud/no-deprecated-library-props -->
					<NcSelect
						v-model="attendees"
						class="schedule-modal__attendees"
						:options="teamOptions"
						:inputLabel="t('recruiting', 'Interviewers')"
						label="displayName"
						:userSelect="true"
						:multiple="true" />
					<!-- eslint-enable @nextcloud/no-deprecated-library-props -->
					<NcSelect
						v-model="duration"
						class="schedule-modal__duration"
						:options="durationOptions"
						:inputLabel="t('recruiting', 'Duration')"
						label="label"
						:clearable="false" />
				</div>

				<NcCheckboxRadioSwitch
					v-if="session.talkAvailable && !interview"
					v-model="isVideo"
					type="switch"
					:title="t('recruiting', 'The room is created once the candidate confirms a slot; the link lands in the calendar event and on the confirmation page')">
					{{ t('recruiting', 'Create a Talk video room for this interview') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-if="!isVideo && !interview"
					v-model="location"
					:label="t('recruiting', 'Location')"
					:placeholder="session.talkAvailable
						? t('recruiting', 'e.g. Office Stuttgart, meeting room 2 — or a video link from another tool')
						: t('recruiting', 'e.g. Office Stuttgart, meeting room 2')" />

				<div class="schedule-modal__slots-header">
					<h3>{{ t('recruiting', 'Proposed time slots') }}</h3>
					<NcButton :disabled="attendees.length === 0 || proposing" @click="propose">
						<template #icon>
							<NcLoadingIcon v-if="proposing" :size="20" />
							<CalendarSearch v-else :size="20" />
						</template>
						{{ t('recruiting', 'Find free slots') }}
					</NcButton>
				</div>
				<p class="schedule-modal__hint">
					{{ t('recruiting', 'Slots are taken from the interviewers\' calendars, within the working hours each of them set in their availability settings (working days 9–17 for those who set none). The candidate picks one of them on a public page.') }}
				</p>

				<ul v-if="slots.length > 0" class="schedule-modal__slots">
					<li v-for="(slot, index) in slots" :key="slot.start">
						<NcCheckboxRadioSwitch
							:modelValue="slot.selected"
							@update:modelValue="slots[index].selected = $event">
							{{ formatDateTime(slot.start) }}
						</NcCheckboxRadioSwitch>
					</li>
				</ul>
				<p v-else-if="proposed && !proposing" class="schedule-modal__hint">
					{{ t('recruiting', 'No common free slot found in the next two weeks — add one manually below.') }}
				</p>

				<div class="schedule-modal__manual">
					<NcDateTimePickerNative
						v-model="manualSlot"
						type="datetime-local"
						:label="t('recruiting', 'Add a slot manually')" />
					<NcButton :disabled="!manualSlot" @click="addManualSlot">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('recruiting', 'Add slot') }}
					</NcButton>
				</div>

				<NcCheckboxRadioSwitch
					v-model="holdSlots"
					type="switch"
					:title="t('recruiting', 'Tentative events titled with the opening only, removed again once the candidate picks a time or the interview is cancelled')">
					{{ t('recruiting', 'Hold the proposed times in the interviewers\' calendars') }}
				</NcCheckboxRadioSwitch>

				<div class="schedule-modal__buttons">
					<NcButton @click="$emit('close')">
						{{ t('recruiting', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="!canCreate || creating" @click="create">
						{{ interview ? t('recruiting', 'Save new times & write email') : t('recruiting', 'Create & write invitation') }}
					</NcButton>
				</div>
			</template>

			<!-- Step 2: the invitation mail -->
			<template v-else>
				<h2>
					{{ interview
						? t('recruiting', 'New times for {name}', { name: candidate.displayName })
						: t('recruiting', 'Invitation for {name}', { name: candidate.displayName }) }}
				</h2>
				<NcNoteCard v-if="!candidate.email" type="warning">
					{{ t('recruiting', 'This candidate has no email address. Copy the scheduling link and send it yourself.') }}
				</NcNoteCard>

				<NcTextField v-model="mailSubject" :label="t('recruiting', 'Subject')" :disabled="!candidate.email" />
				<NcTextArea
					v-model="mailBody"
					:label="t('recruiting', 'Message')"
					:helperText="t('recruiting', 'The scheduling link stays in the mail — it is how the candidate picks a slot.')"
					rows="12"
					resize="vertical"
					:disabled="!candidate.email" />
				<AiAssist
					v-if="candidate.email"
					:candidateId="candidate.id"
					action="draft_invite"
					:label="t('recruiting', 'Draft with Assistant')"
					@result="onAiDraft" />

				<div class="schedule-modal__buttons">
					<NcButton @click="copyLink">
						<template #icon>
							<ContentCopy :size="20" />
						</template>
						{{ t('recruiting', 'Copy scheduling link') }}
					</NcButton>
					<NcButton variant="tertiary" @click="finish">
						{{ t('recruiting', 'Send later') }}
					</NcButton>
					<NcButton
						v-if="candidate.email"
						variant="primary"
						:disabled="sending"
						@click="sendInvite">
						<template #icon>
							<SendOutline :size="20" />
						</template>
						{{ interview ? t('recruiting', 'Send new times') : t('recruiting', 'Send invitation') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CalendarSearch from 'vue-material-design-icons/CalendarSearch.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import SendOutline from 'vue-material-design-icons/SendOutline.vue'
import AiAssist from './AiAssist.vue'
import api, { errorMessage } from '../api.js'
import { useOpeningsStore, useSessionStore, useSidebarStore } from '../store.js'
import { copyToClipboard } from '../utils/clipboard.js'
import { formatDateTime } from '../utils/format.js'

export default {
	name: 'ScheduleInterviewModal',
	components: {
		AiAssist,
		CalendarSearch,
		ContentCopy,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDateTimePickerNative,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		Plus,
		SendOutline,
	},

	props: {
		candidate: { type: Object, required: true },
		/** An existing interview to propose other times for, instead of booking a new one */
		interview: { type: Object, default: null },
	},

	emits: ['close', 'saved'],
	setup() {
		return {
			session: useSessionStore(),
			openings: useOpeningsStore(),
			sidebar: useSidebarStore(),
		}
	},

	data() {
		return {
			step: 1,
			title: this.t('recruiting', 'Interview'),
			attendees: [],
			duration: null,
			isVideo: false,
			location: '',
			slots: [],
			proposed: false,
			proposing: false,
			manualSlot: null,
			creating: false,
			holdSlots: true,
			saved: null,
			mailSubject: '',
			mailBody: '',
			sending: false,
		}
	},

	computed: {
		teamOptions() {
			const opening = this.openings.byId(this.candidate.openingId)
			const options = (opening?.team ?? [])
				.filter((member) => member.role !== 'observer')
				.map((member) => ({ id: member.uid, user: member.uid, displayName: member.displayName }))
			if (this.session.uid && !options.some((option) => option.id === this.session.uid)) {
				options.unshift({ id: this.session.uid, user: this.session.uid, displayName: this.session.displayName })
			}
			return options
		},

		durationOptions() {
			return [30, 45, 60, 90, 120].map((minutes) => ({
				id: minutes,
				label: this.t('recruiting', '{minutes} minutes', { minutes }),
			}))
		},

		selectedSlots() {
			return this.slots.filter((slot) => slot.selected)
		},

		canCreate() {
			return this.attendees.length > 0 && this.selectedSlots.length > 0
		},
	},

	created() {
		this.duration = this.durationOptions[2]
		this.isVideo = this.session.talkAvailable
		if (this.interview) {
			this.title = this.interview.title
			this.attendees = this.interview.attendees.map((attendee) => ({ id: attendee.uid, user: attendee.uid, displayName: attendee.displayName }))
			this.duration = this.durationOptions.find((option) => option.id === this.interview.durationMin)
				?? { id: this.interview.durationMin, label: this.t('recruiting', '{minutes} minutes', { minutes: this.interview.durationMin }) }
		}
	},

	methods: {
		formatDateTime,
		async propose() {
			this.proposing = true
			try {
				const { data } = await api.proposeSlots(this.candidate.id, {
					attendees: this.attendees.map((attendee) => attendee.id),
					durationMin: this.duration.id,
					timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
				})
				const existing = this.slots.filter((slot) => slot.manual)
				this.slots = this.sortByInstant([
					...existing,
					...data.map((slot) => ({ ...slot, selected: true })),
				])
				this.proposed = true
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not query the calendars')))
			} finally {
				this.proposing = false
			}
		},

		addManualSlot() {
			if (!this.manualSlot) {
				return
			}
			const start = new Date(this.manualSlot)
			if (start <= new Date()) {
				showError(this.t('recruiting', 'Slots must be in the future'))
				return
			}
			this.slots.push({ start: start.toISOString(), selected: true, manual: true })
			this.slots = this.sortByInstant(this.slots)
			this.manualSlot = null
		},

		/**
		 * Proposals come back as ATOM with the server's offset, manual slots
		 * as UTC "Z" — comparing those as strings sorts by text, not by time.
		 *
		 * @param slots
		 */
		sortByInstant(slots) {
			return [...slots].sort((a, b) => new Date(a.start) - new Date(b.start))
		},

		async create() {
			this.creating = true
			try {
				const slots = this.selectedSlots.map((slot) => ({ start: slot.start }))
				const { data } = this.interview
					? await api.rescheduleInterview(this.interview.id, { slots, holdSlots: this.holdSlots })
					: await api.createInterview(this.candidate.id, {
							title: this.title,
							attendees: this.attendees.map((attendee) => attendee.id),
							durationMin: this.duration.id,
							isVideo: this.isVideo,
							location: this.isVideo ? '' : this.location,
							slots,
							holdSlots: this.holdSlots,
						})
				this.saved = data.interview
				this.mailSubject = data.inviteDraft.subject
				this.mailBody = data.inviteDraft.body
				this.step = 2
			} catch (error) {
				showError(errorMessage(error, this.interview
					? this.t('recruiting', 'Could not save the new times')
					: this.t('recruiting', 'Could not create the interview')))
			} finally {
				this.creating = false
			}
		},

		async sendInvite() {
			this.sending = true
			try {
				await api.sendInvite(this.saved.id, this.mailSubject, this.mailBody)
				showSuccess(this.interview ? this.t('recruiting', 'New times sent') : this.t('recruiting', 'Invitation sent'))
				this.finish()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not send the invitation')))
			} finally {
				this.sending = false
			}
		},

		onAiDraft(text) {
			// Keep the scheduling link — the backend re-appends it if lost,
			// but the preview should already show the final mail.
			this.mailBody = text.trim() + '\n\n' + this.saved.publicUrl
		},

		copyLink() {
			copyToClipboard(this.saved.publicUrl)
		},

		finish() {
			this.sidebar.touch()
			this.$emit('saved')
		},
	},
}
</script>

<style scoped lang="scss">
.schedule-modal {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);

	h2,
	h3 {
		margin: 0;
	}

	&__row {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: flex-end;
	}

	&__attendees {
		flex: 2;
	}

	&__duration {
		flex: 1;
		min-width: 140px;
	}

	&__slots-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	&__slots {
		display: flex;
		flex-direction: column;
	}

	&__manual {
		display: flex;
		align-items: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
		margin-top: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
