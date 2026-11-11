<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal :name="t('recruiting', 'Write email')" size="normal" @close="$emit('close')">
		<div class="write-email">
			<h2>{{ t('recruiting', 'Email to {name}', { name: candidate.displayName }) }}</h2>
			<p class="write-email__hint">
				{{ t('recruiting', 'To {email}. The email is logged on the candidate’s “Log” tab.', { email: candidate.email }) }}
			</p>

			<TemplatePicker
				:templates="templates"
				:modelValue="templateId"
				:disabled="loadingTemplate"
				@update:modelValue="loadTemplate" />
			<NcLoadingIcon v-if="loadingTemplate && templates.length === 0" :size="24" />
			<template v-else>
				<NcTextField v-model="subject" :label="t('recruiting', 'Subject')" />
				<NcTextArea
					v-model="body"
					:label="t('recruiting', 'Message')"
					:helperText="t('recruiting', 'Placeholders such as {placeholder} are filled in.', { placeholder: '{{candidate_name}}' })"
					rows="10"
					resize="vertical" />
				<AiAssist
					:candidateId="candidate.id"
					action="draft_email"
					:params="{ purpose: subject }"
					:label="t('recruiting', 'Draft with Assistant')"
					@result="body = $event" />
				<div class="write-email__preview" aria-live="polite">
					<div class="write-email__preview-label">
						{{ t('recruiting', 'Preview') }}
					</div>
					<NcLoadingIcon v-if="loadingPreview" :size="20" />
					<template v-else>
						<strong>{{ preview.subject }}</strong>
						<p class="write-email__preview-body">
							{{ preview.body }}
						</p>
					</template>
				</div>
			</template>

			<div class="write-email__buttons">
				<NcButton @click="$emit('close')">
					{{ t('recruiting', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSend" @click="send">
					<template #icon>
						<NcLoadingIcon v-if="sending" :size="20" />
						<SendOutline v-else :size="20" />
					</template>
					{{ t('recruiting', 'Send email') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SendOutline from 'vue-material-design-icons/SendOutline.vue'
import AiAssist from './AiAssist.vue'
import TemplatePicker from './TemplatePicker.vue'
import api, { errorMessage } from '../api.js'
import livePreview from '../utils/livePreview.js'

/**
 * "Write email": a personal mail to the candidate, from a template or
 * from scratch, sent with the candidate's reply address and logged.
 */
export default {
	name: 'WriteEmailModal',
	components: {
		AiAssist,
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcTextArea,
		NcTextField,
		SendOutline,
		TemplatePicker,
	},

	mixins: [livePreview('custom')],

	props: {
		candidate: { type: Object, required: true },
	},

	emits: ['close', 'sent'],
	data() {
		return {
			subject: '',
			body: '',
			templates: [],
			templateId: null,
			loadingTemplate: false,
			sending: false,
		}
	},

	computed: {
		previewCandidateId() {
			return this.candidate.id
		},

		canSend() {
			return !this.sending && !this.loadingTemplate && this.subject.trim() !== '' && this.body.trim() !== ''
		},
	},

	created() {
		this.loadTemplate(null)
	},

	methods: {
		/**
		 * @param {?string} templateId null = the default for the candidate's language
		 */
		async loadTemplate(templateId) {
			this.loadingTemplate = true
			try {
				const { data } = await api.previewMail(this.candidate.id, 'custom', templateId)
				this.templates = data.templates ?? []
				this.templateId = String(data.templateId)
				this.subject = data.template?.subject ?? data.subject
				this.body = data.template?.body ?? data.body
				this.setPreview(data)
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load the mail template')))
			} finally {
				this.loadingTemplate = false
			}
		},

		async send() {
			this.sending = true
			try {
				await api.writeEmail(this.candidate.id, this.subject, this.body)
				showSuccess(this.t('recruiting', 'Email sent'))
				this.$emit('sent')
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not send the email')))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.write-email {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);

	h2 {
		margin: 0;
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__preview {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 3);
		max-height: 220px;
		overflow-y: auto;
	}

	&__preview-label {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		margin-bottom: var(--default-grid-baseline);
	}

	&__preview-body {
		white-space: pre-wrap;
		margin: var(--default-grid-baseline) 0 0;
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
