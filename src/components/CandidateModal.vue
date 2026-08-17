<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal :name="t('recruiting', 'Add candidate')" size="normal" @close="$emit('close')">
		<div class="candidate-modal">
			<h2>{{ t('recruiting', 'Add candidate') }}</h2>

			<NcTextField v-model="form.displayName" :label="t('recruiting', 'Full name')" required />
			<div class="candidate-modal__row">
				<NcTextField v-model="form.email" type="email" :label="t('recruiting', 'Email address')" />
				<NcTextField v-model="form.phone" type="tel" :label="t('recruiting', 'Phone')" />
			</div>

			<div
				class="candidate-modal__dropzone"
				:class="{ 'candidate-modal__dropzone--over': dragOver }"
				@dragover.prevent="dragOver = true"
				@dragleave="dragOver = false"
				@drop.prevent="onDrop">
				<Paperclip :size="20" />
				<span>{{ t('recruiting', 'Drop CV and documents here, or') }}</span>
				<NcButton variant="tertiary" @click="$refs.fileInput.click()">
					{{ t('recruiting', 'browse files') }}
				</NcButton>
				<input
					ref="fileInput"
					type="file"
					multiple
					accept=".pdf,.doc,.docx,.odt,.txt,.md,.png,.jpg,.jpeg,.webp"
					class="hidden-visually"
					@change="onPick">
			</div>
			<ul v-if="files.length > 0" class="candidate-modal__files">
				<li v-for="(file, index) in files" :key="index">
					📎 {{ file.name }} ({{ formatSize(file.size) }})
					<NcButton
						variant="tertiary"
						:ariaLabel="t('recruiting', 'Remove file')"
						@click="files.splice(index, 1)">
						<template #icon>
							<Close :size="16" />
						</template>
					</NcButton>
				</li>
			</ul>

			<div class="candidate-modal__buttons">
				<NcButton @click="$emit('close')">
					{{ t('recruiting', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving || !form.displayName.trim()" @click="save">
					{{ t('recruiting', 'Add candidate') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Close from 'vue-material-design-icons/Close.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import api, { errorMessage } from '../api.js'
import { formatSize } from '../utils/format.js'

export default {
	name: 'CandidateModal',
	components: { Close, NcButton, NcModal, NcTextField, Paperclip },
	props: {
		openingId: { type: Number, required: true },
	},

	emits: ['close', 'saved'],
	data() {
		return {
			saving: false,
			dragOver: false,
			files: [],
			form: {
				displayName: '',
				email: '',
				phone: '',
			},
		}
	},

	methods: {
		formatSize,
		onDrop(event) {
			this.dragOver = false
			this.files.push(...Array.from(event.dataTransfer.files ?? []))
		},

		onPick(event) {
			this.files.push(...Array.from(event.target.files ?? []))
			event.target.value = ''
		},

		async save() {
			this.saving = true
			try {
				const { data } = await api.createCandidate({ ...this.form, openingId: this.openingId })
				for (const file of this.files) {
					try {
						await api.uploadDocument(data.id, file)
					} catch (error) {
						showError(errorMessage(error, this.t('recruiting', 'Could not upload {name}', { name: file.name })))
					}
				}
				showSuccess(this.t('recruiting', 'Candidate added'))
				this.$emit('saved', data)
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not add the candidate')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.candidate-modal {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);

	h2 {
		margin: 0;
	}

	&__row {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__dropzone {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: var(--default-grid-baseline);
		border: 2px dashed var(--color-border-maxcontrast);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 5);
		color: var(--color-text-maxcontrast);

		&--over {
			border-color: var(--color-primary-element);
			background-color: var(--color-primary-element-light);
		}
	}

	&__files {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);

		li {
			display: flex;
			align-items: center;
			gap: var(--default-grid-baseline);
		}
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
