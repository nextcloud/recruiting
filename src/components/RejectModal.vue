<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal :name="t('recruiting', 'Reject candidate')" size="normal" @close="$emit('close')">
		<div class="reject-modal">
			<h2>{{ t('recruiting', 'Reject {name}', { name: candidate.displayName }) }}</h2>

			<NcSelect
				v-model="reason"
				:options="reasonOptions"
				:inputLabel="t('recruiting', 'Reason (internal, used for reporting)')"
				label="label"
				:clearable="false" />

			<NcCheckboxRadioSwitch v-model="sendMail" type="switch" :disabled="!candidate.email">
				{{ candidate.email
					? t('recruiting', 'Send a rejection email to {email}', { email: candidate.email })
					: t('recruiting', 'No email address on file — the candidate cannot be notified from here') }}
			</NcCheckboxRadioSwitch>

			<template v-if="sendMail && candidate.email">
				<NcLoadingIcon v-if="loadingPreview" :size="24" />
				<template v-else>
					<NcTextField v-model="subject" :label="t('recruiting', 'Subject')" />
					<NcTextArea
						v-model="body"
						:label="t('recruiting', 'Message')"
						:helperText="t('recruiting', 'Review and adjust before sending — nothing goes out unreviewed.')"
						rows="10"
						resize="vertical" />
					<AiAssist
						:candidateId="candidate.id"
						action="draft_rejection"
						:label="t('recruiting', 'Draft with Assistant')"
						@result="body = $event" />
				</template>

				<NcCheckboxRadioSwitch v-model="askPool" type="switch">
					{{ t('recruiting', 'Ask to stay in our talent pool — adds a consent link to the email (GDPR: only stored after the candidate confirms)') }}
				</NcCheckboxRadioSwitch>
			</template>

			<div class="reject-modal__buttons">
				<NcButton @click="$emit('close')">
					{{ t('recruiting', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" :disabled="working || (sendMail && candidate.email && loadingPreview)" @click="reject">
					{{ sendMail && candidate.email
						? t('recruiting', 'Send email & reject')
						: t('recruiting', 'Reject without email') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api, { errorMessage } from '../api.js'
import { useSessionStore, useSidebarStore } from '../store.js'
import { reasonLabel } from '../utils/format.js'
import AiAssist from './AiAssist.vue'

export default {
	name: 'RejectModal',
	components: {
		AiAssist,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcSelect,
		NcTextArea,
		NcTextField,
	},
	props: {
		candidate: { type: Object, required: true },
	},
	emits: ['close', 'rejected'],
	setup() {
		return {
			session: useSessionStore(),
			sidebar: useSidebarStore(),
		}
	},
	data() {
		return {
			reason: null,
			sendMail: Boolean(this.candidate.email),
			askPool: false,
			subject: '',
			body: '',
			loadingPreview: false,
			working: false,
		}
	},
	computed: {
		reasonOptions() {
			return this.session.rejectionReasons.map((reason) => ({ id: reason, label: reasonLabel(reason) }))
		},
	},
	async created() {
		this.reason = this.reasonOptions[0] ?? null
		if (this.candidate.email) {
			this.loadingPreview = true
			try {
				const { data } = await api.previewMail(this.candidate.id, 'rejection')
				this.subject = data.subject
				this.body = data.body
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load the mail template')))
				this.sendMail = false
			} finally {
				this.loadingPreview = false
			}
		}
	},
	methods: {
		async reject() {
			this.working = true
			try {
				const payload = { reason: this.reason?.id ?? 'other' }
				if (this.sendMail && this.candidate.email) {
					payload.mailSubject = this.subject
					payload.mailBody = this.body
					payload.askPool = this.askPool
				}
				await api.rejectCandidate(this.candidate.id, payload)
				showSuccess(this.t('recruiting', 'Candidate rejected'))
				this.sidebar.touch()
				this.$emit('rejected')
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not reject the candidate')))
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.reject-modal {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);

	h2 {
		margin: 0;
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
