<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal :name="t('recruiting', 'Cancel interview')" size="normal" @close="$emit('close')">
		<div class="cancel-modal">
			<h2>{{ t('recruiting', 'Cancel "{title}"?', { title: interview.title }) }}</h2>
			<p class="cancel-modal__hint">
				{{ t('recruiting', 'Interviewers are notified and the calendar event is cancelled. The candidate\'s scheduling link stops working.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="sendMail" type="switch" :disabled="!candidate.email">
				{{ candidate.email
					? t('recruiting', 'Tell the candidate by email ({email})', { email: candidate.email })
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
				</template>
			</template>

			<div class="cancel-modal__buttons">
				<NcButton @click="$emit('close')">
					{{ t('recruiting', 'Keep interview') }}
				</NcButton>
				<NcButton variant="error" :disabled="working || (mailing && loadingPreview)" @click="cancel">
					{{ mailing ? t('recruiting', 'Send email & cancel') : t('recruiting', 'Cancel without email') }}
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
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api, { errorMessage } from '../api.js'

export default {
	name: 'CancelInterviewModal',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcTextArea,
		NcTextField,
	},

	props: {
		candidate: { type: Object, required: true },
		interview: { type: Object, required: true },
	},

	emits: ['close', 'cancelled'],
	data() {
		return {
			sendMail: Boolean(this.candidate.email),
			subject: '',
			body: '',
			loadingPreview: false,
			working: false,
		}
	},

	computed: {
		mailing() {
			return this.sendMail && Boolean(this.candidate.email)
		},
	},

	async created() {
		if (!this.candidate.email) {
			return
		}
		this.loadingPreview = true
		try {
			const { data } = await api.previewCancelMail(this.interview.id)
			this.subject = data.subject
			this.body = data.body
		} catch (error) {
			showError(errorMessage(error, this.t('recruiting', 'Could not load the mail template')))
			this.sendMail = false
		} finally {
			this.loadingPreview = false
		}
	},

	methods: {
		async cancel() {
			this.working = true
			try {
				await api.cancelInterview(this.interview.id, this.mailing ? { mailSubject: this.subject, mailBody: this.body } : {})
				showSuccess(this.t('recruiting', 'Interview cancelled'))
				this.$emit('cancelled')
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not cancel the interview')))
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.cancel-modal {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);

	h2 {
		margin: 0;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
