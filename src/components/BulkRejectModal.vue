<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:name="heading"
		size="normal"
		:noClose="phase === 'working'"
		@close="phase !== 'working' && $emit('close')">
		<div class="bulk-reject">
			<h2>{{ heading }}</h2>

			<template v-if="phase === 'compose'">
				<p v-if="intro" class="bulk-reject__intro">
					{{ intro }}
				</p>

				<NcSelect
					v-model="reason"
					:options="reasonOptions"
					:inputLabel="t('recruiting', 'Reason (internal, used for reporting)')"
					label="label"
					:clearable="false" />

				<NcCheckboxRadioSwitch v-model="sendMail" type="switch" :disabled="withEmail.length === 0">
					{{ withEmail.length > 0
						? n('recruiting', 'Send the rejection email to %n candidate', 'Send the rejection email to %n candidates', withEmail.length)
						: t('recruiting', 'None of these candidates has an email address — they cannot be notified from here') }}
				</NcCheckboxRadioSwitch>
				<p v-if="sendMail && withoutEmail > 0" class="bulk-reject__hint">
					{{ n('recruiting', '%n candidate has no email address and is rejected without an email.', '%n candidates have no email address and are rejected without an email.', withoutEmail) }}
				</p>

				<template v-if="sendMail && withEmail.length > 0">
					<TemplatePicker
						:templates="templates"
						:modelValue="templateId"
						:perCandidate="true"
						:disabled="loadingTemplate"
						@update:modelValue="loadTemplate" />
					<NcLoadingIcon v-if="loadingTemplate" :size="24" />
					<template v-else>
						<NcTextField v-model="subject" :label="t('recruiting', 'Subject')" />
						<NcTextArea
							v-model="body"
							:label="t('recruiting', 'Message')"
							:helperText="t('recruiting', 'Placeholders such as {placeholder} are filled in for each candidate.', { placeholder: '{{candidate_name}}' })"
							rows="8"
							resize="vertical" />
						<p v-if="perCandidateLanguage" class="bulk-reject__hint">
							{{ t('recruiting', 'As long as you leave the text unchanged, each candidate receives this template in their own language.') }}
						</p>
						<div class="bulk-reject__preview" aria-live="polite">
							<div class="bulk-reject__preview-label">
								{{ t('recruiting', 'Preview for {name}', { name: previewCandidate.displayName }) }}
							</div>
							<NcLoadingIcon v-if="loadingPreview" :size="20" />
							<template v-else>
								<strong>{{ preview.subject }}</strong>
								<p class="bulk-reject__preview-body">
									{{ preview.body }}
								</p>
							</template>
						</div>
					</template>

					<NcCheckboxRadioSwitch v-model="askPool" type="switch">
						{{ t('recruiting', 'Ask to stay in our talent pool — adds a consent link to the email (GDPR: only stored after the candidate confirms)') }}
					</NcCheckboxRadioSwitch>
				</template>

				<div class="bulk-reject__buttons">
					<NcButton @click="$emit('close')">
						{{ t('recruiting', 'Cancel') }}
					</NcButton>
					<NcButton variant="error" :disabled="!canStart" @click="run">
						{{ sendMail && withEmail.length > 0
							? t('recruiting', 'Send emails & reject')
							: t('recruiting', 'Reject without email') }}
					</NcButton>
				</div>
			</template>

			<template v-else-if="phase === 'working'">
				<NcProgressBar :value="progressPercent" size="medium" />
				<p>{{ t('recruiting', '{done} of {total} candidates processed …', { done: processed, total: candidates.length }) }}</p>
			</template>

			<template v-else>
				<NcNoteCard v-if="result.rejected.length > 0" type="success">
					{{ n('recruiting', '%n candidate rejected.', '%n candidates rejected.', result.rejected.length) }}
					<template v-if="rejectedWithoutMail > 0">
						{{ n('recruiting', '%n of them without an email.', '%n of them without an email.', rejectedWithoutMail) }}
					</template>
				</NcNoteCard>
				<NcNoteCard v-if="result.failed.length > 0" type="error" :heading="n('recruiting', '%n candidate could not be rejected', '%n candidates could not be rejected', result.failed.length)">
					<ul class="bulk-reject__failures">
						<li v-for="failure in result.failed" :key="failure.id">
							<strong>{{ failureName(failure) }}</strong>: {{ failure.message }}
						</li>
					</ul>
				</NcNoteCard>
				<div class="bulk-reject__buttons">
					<NcButton variant="primary" @click="$emit('close')">
						{{ t('recruiting', 'Close') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import TemplatePicker from './TemplatePicker.vue'
import api, { errorMessage } from '../api.js'
import { useSessionStore, useSidebarStore } from '../store.js'
import { reasonLabel } from '../utils/format.js'
import livePreview from '../utils/livePreview.js'

/** Matches BulkRejectService::MAX_PER_REQUEST on the server */
const CHUNK_SIZE = 50

export default {
	name: 'BulkRejectModal',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcProgressBar,
		NcSelect,
		NcTextArea,
		NcTextField,
		TemplatePicker,
	},

	mixins: [livePreview('rejection')],

	props: {
		/** board cards: at least id, displayName and email */
		candidates: { type: Array, required: true },
		presetReason: { type: String, default: null },
		intro: { type: String, default: '' },
	},

	emits: ['close', 'finished'],
	setup() {
		return {
			session: useSessionStore(),
			sidebar: useSidebarStore(),
		}
	},

	data() {
		return {
			phase: 'compose',
			reason: null,
			sendMail: false,
			askPool: false,
			subject: '',
			body: '',
			templates: [],
			templateId: '',
			loadedText: null,
			loadingTemplate: false,
			processed: 0,
			result: { rejected: [], failed: [] },
		}
	},

	computed: {
		heading() {
			return this.n('recruiting', 'Reject %n candidate', 'Reject %n candidates', this.candidates.length)
		},

		reasonOptions() {
			return this.session.rejectionReasons.map((reason) => ({ id: reason, label: reasonLabel(reason) }))
		},

		withEmail() {
			return this.candidates.filter((candidate) => candidate.email)
		},

		withoutEmail() {
			return this.candidates.length - this.withEmail.length
		},

		previewCandidate() {
			return this.withEmail[0] ?? null
		},

		previewCandidateId() {
			return this.previewCandidate?.id ?? null
		},

		edited() {
			return this.subject + '\0' + this.body !== this.loadedText
		},

		/** an unedited built-in or default template goes out per candidate language */
		perCandidateLanguage() {
			return !this.edited && (this.templateId === '' || String(this.templateId).startsWith('builtin:'))
		},

		canStart() {
			if (this.candidates.length === 0 || this.reason === null) {
				return false
			}
			if (!this.sendMail || this.withEmail.length === 0) {
				return true
			}
			return !this.loadingTemplate && this.subject.trim() !== '' && this.body.trim() !== ''
		},

		progressPercent() {
			return this.candidates.length > 0 ? Math.round(100 * this.processed / this.candidates.length) : 0
		},

		rejectedWithoutMail() {
			return this.result.rejected.filter((entry) => !entry.mailed).length
		},

		namesById() {
			return Object.fromEntries(this.candidates.map((candidate) => [candidate.id, candidate.displayName]))
		},
	},

	created() {
		this.reason = this.reasonOptions.find((option) => option.id === this.presetReason) ?? this.reasonOptions[0] ?? null
		this.sendMail = this.withEmail.length > 0
		if (this.previewCandidate !== null) {
			this.loadTemplate('')
		}
	},

	methods: {
		/**
		 * @param {string} templateId '' = each candidate's default template
		 */
		async loadTemplate(templateId) {
			this.templateId = templateId
			this.loadingTemplate = true
			try {
				const { data } = await api.previewMail(this.previewCandidate.id, 'rejection', templateId || null)
				this.templates = data.templates ?? []
				this.subject = data.template?.subject ?? data.subject
				this.body = data.template?.body ?? data.body
				this.loadedText = this.subject + '\0' + this.body
				this.setPreview(data)
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load the mail template')))
				if (this.templates.length === 0) {
					this.sendMail = false
				}
			} finally {
				this.loadingTemplate = false
			}
		},

		failureName(failure) {
			return failure.name || this.namesById[failure.id] || this.t('recruiting', 'Candidate #{id}', { id: failure.id })
		},

		/**
		 * One request per CHUNK_SIZE candidates, one after the other, so no
		 * request runs too long and the progress stays visible.
		 */
		async run() {
			const mail = this.sendMail && this.withEmail.length > 0
			// An unedited template is rendered per candidate, in their language
			const perCandidate = mail && !this.edited
			const payload = {
				reason: this.reason.id,
				sendMail: mail,
				subject: mail && !perCandidate ? this.subject : '',
				body: mail && !perCandidate ? this.body : '',
				askPool: mail && this.askPool,
			}
			if (perCandidate) {
				payload.templateId = this.templateId
			}
			const ids = this.candidates.map((candidate) => candidate.id)
			this.phase = 'working'
			this.processed = 0
			for (let start = 0; start < ids.length; start += CHUNK_SIZE) {
				const chunk = ids.slice(start, start + CHUNK_SIZE)
				try {
					const { data } = await api.bulkReject({ ...payload, ids: chunk })
					this.result.rejected.push(...data.rejected)
					this.result.failed.push(...data.failed)
				} catch (error) {
					const message = errorMessage(error, this.t('recruiting', 'Could not reject the candidate'))
					this.result.failed.push(...chunk.map((id) => ({ id, name: '', message })))
				}
				this.processed += chunk.length
			}
			this.phase = 'done'
			this.sidebar.touch()
			this.$emit('finished', this.result)
		},
	},
}
</script>

<style scoped lang="scss">
.bulk-reject {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);

	h2 {
		margin: 0;
	}

	&__intro,
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

	&__failures {
		margin: 0;
		padding-inline-start: calc(var(--default-grid-baseline) * 4);
		list-style: disc;
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
