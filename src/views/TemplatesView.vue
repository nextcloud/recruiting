<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="templates-view">
		<h2>{{ t('recruiting', 'Mail templates') }}</h2>
		<p class="templates-view__hint">
			{{ t('recruiting', 'Templates for candidate mail. The built-in templates are always available; mark one of your own as default to replace them. Every send shows a resolved preview first.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="44" class="templates-view__loading" />

		<div v-else class="templates-view__sections">
			<section v-for="type in types" :key="type.id" class="templates-view__section">
				<h3>{{ type.label }}</h3>
				<div class="templates-view__cards">
					<div
						v-for="template in byType(type.id)"
						:key="template.id"
						class="template-card"
						:class="{ 'template-card--default': template.isDefault }">
						<div class="template-card__head">
							<strong>{{ template.name }}</strong>
							<span v-if="template.isDefault" class="template-card__badge">{{ t('recruiting', 'Default') }}</span>
							<span v-else-if="template.builtin" class="template-card__badge template-card__badge--builtin">{{ t('recruiting', 'Built-in') }}</span>
						</div>
						<p class="template-card__subject">
							{{ template.subject }}
						</p>
						<p class="template-card__body">
							{{ template.body }}
						</p>
						<div class="template-card__actions">
							<NcButton v-if="!template.builtin" variant="tertiary" @click="edit(template)">
								{{ t('recruiting', 'Edit') }}
							</NcButton>
							<NcButton v-if="template.builtin" variant="tertiary" @click="duplicate(template)">
								{{ t('recruiting', 'Customize') }}
							</NcButton>
							<NcButton
								v-if="!template.builtin && !template.isDefault"
								variant="tertiary"
								@click="makeDefault(template)">
								{{ t('recruiting', 'Make default') }}
							</NcButton>
							<NcButton v-if="!template.builtin" variant="tertiary" @click="remove(template)">
								{{ t('recruiting', 'Delete') }}
							</NcButton>
						</div>
					</div>
				</div>
			</section>
		</div>

		<NcModal
			v-if="editing"
			:name="t('recruiting', 'Edit template')"
			size="normal"
			@close="editing = null">
			<div class="template-editor">
				<h2>{{ editing.id ? t('recruiting', 'Edit template') : t('recruiting', 'New template') }}</h2>
				<NcTextField v-model="editing.name" :label="t('recruiting', 'Template name')" />
				<NcTextField v-model="editing.subject" :label="t('recruiting', 'Subject')" />
				<div class="template-editor__placeholders">
					<span class="template-editor__placeholders-label">{{ t('recruiting', 'Placeholders — click to insert:') }}</span>
					<button
						v-for="placeholder in placeholdersFor(editing.type)"
						:key="placeholder"
						type="button"
						class="template-editor__placeholder"
						:title="t('recruiting', 'Inserted at the cursor position in the body')"
						@click="insertPlaceholder(placeholder)">
						<code>{{ wrap(placeholder) }}</code>
					</button>
				</div>
				<NcTextArea
					ref="bodyField"
					v-model="editing.body"
					:label="t('recruiting', 'Body')"
					rows="12"
					resize="vertical" />
				<NcCheckboxRadioSwitch v-model="editing.isDefault" type="switch">
					{{ t('recruiting', 'Use as default for this mail type') }}
				</NcCheckboxRadioSwitch>
				<div class="template-editor__buttons">
					<NcButton @click="editing = null">
						{{ t('recruiting', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="saving" @click="save">
						{{ t('recruiting', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
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
import { confirmDestructive } from '../utils/confirm.js'

export default {
	name: 'TemplatesView',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcTextArea,
		NcTextField,
	},

	data() {
		return {
			templates: [],
			loading: true,
			editing: null,
			saving: false,
		}
	},

	computed: {
		types() {
			return [
				{ id: 'receipt_confirmation', label: this.t('recruiting', 'Confirmation of receipt') },
				{ id: 'interview_invite', label: this.t('recruiting', 'Interview invitation') },
				{ id: 'offer', label: this.t('recruiting', 'Offer') },
				{ id: 'rejection', label: this.t('recruiting', 'Rejection') },
			]
		},
	},

	created() {
		this.load()
	},

	methods: {
		byType(type) {
			return this.templates.filter((template) => template.type === type)
		},

		// Only the placeholders this mail type can actually resolve
		placeholdersFor(type) {
			const common = ['candidate_name', 'opening_title', 'company', 'sender_name']
			if (type === 'interview_invite') {
				return [...common, 'interview_title', 'interview_link']
			}
			if (type === 'offer') {
				return [...common, 'offer_job_title', 'offer_start_date', 'offer_valid_until']
			}
			return common
		},

		// Vue's template parser would end the mustache at a literal "}}",
		// so the token is built here instead of inline
		wrap(placeholder) {
			return '{{' + placeholder + '}}'
		},

		insertPlaceholder(placeholder) {
			const token = this.wrap(placeholder)
			const textarea = this.$refs.bodyField?.$el?.querySelector('textarea')
			if (!textarea) {
				this.editing.body += token
				return
			}
			const start = textarea.selectionStart ?? this.editing.body.length
			const end = textarea.selectionEnd ?? start
			this.editing.body = this.editing.body.slice(0, start) + token + this.editing.body.slice(end)
			this.$nextTick(() => {
				textarea.focus()
				textarea.setSelectionRange(start + token.length, start + token.length)
			})
		},

		async load() {
			this.loading = true
			try {
				const { data } = await api.getTemplates()
				this.templates = data
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load the templates')))
			} finally {
				this.loading = false
			}
		},

		edit(template) {
			this.editing = { ...template }
		},

		duplicate(template) {
			this.editing = {
				id: null,
				type: template.type,
				name: this.t('recruiting', 'Custom {name}', { name: template.name.replace(/ \(.*\)$/, '') }),
				subject: template.subject,
				body: template.body,
				isDefault: true,
			}
		},

		async save() {
			this.saving = true
			try {
				if (this.editing.id) {
					await api.updateTemplate(this.editing.id, this.editing)
				} else {
					await api.createTemplate(this.editing)
				}
				this.editing = null
				showSuccess(this.t('recruiting', 'Template saved'))
				this.load()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not save the template')))
			} finally {
				this.saving = false
			}
		},

		async makeDefault(template) {
			try {
				await api.updateTemplate(template.id, { ...template, isDefault: true })
				this.load()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not update the template')))
			}
		},

		async remove(template) {
			if (!await confirmDestructive(
				this.t('recruiting', 'Delete the template "{name}"?', { name: template.name }),
				this.t('recruiting', 'Mails already sent keep their text; the template is gone for future mails.'),
				this.t('recruiting', 'Delete'),
			)) {
				return
			}
			try {
				await api.deleteTemplate(template.id)
				this.load()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not delete the template')))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.templates-view {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	max-width: 1600px;

	h2 {
		margin: 0;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 0;
		font-family: inherit;
		max-width: 900px;
	}

	&__loading {
		margin-top: 15vh;
	}

	// One column per mail type, side by side — the page used to stack the
	// four sections into a single narrow column with 70% of the width empty
	&__sections {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
		gap: calc(var(--default-grid-baseline) * 4);
		align-items: start;
	}

	&__section h3 {
		margin: 0 0 calc(var(--default-grid-baseline) * 2);
	}

	&__cards {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
	}
}

.template-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&--default {
		border-color: var(--color-primary-element);
	}

	&__head {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__badge {
		border-radius: var(--border-radius-pill);
		padding: 1px 8px;
		font-size: 0.8em;
		background-color: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);

		&--builtin {
			background-color: var(--color-background-hover);
			color: var(--color-text-maxcontrast);
		}
	}

	&__subject {
		font-weight: 600;
		margin: 0;
	}

	&__body {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		white-space: pre-wrap;
		margin: 0;
		display: -webkit-box;
		-webkit-line-clamp: 4;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: var(--default-grid-baseline);
		margin-top: auto;
	}
}

.template-editor {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);

	h2 {
		margin: 0;
	}

	&__placeholders {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: var(--default-grid-baseline);
	}

	&__placeholders-label {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		margin-inline-end: var(--default-grid-baseline);
	}

	&__placeholder {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-pill);
		background-color: var(--color-background-hover);
		padding: 2px 10px;
		cursor: pointer;
		transition: background-color var(--animation-quick);

		&:hover {
			background-color: var(--color-background-dark);
		}

		code {
			font-size: 0.85em;
			background: none;
		}
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
