<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSelect
		v-if="options.length > 1"
		:modelValue="selected"
		:options="options"
		:inputLabel="t('recruiting', 'Template')"
		label="label"
		:clearable="false"
		:disabled="disabled"
		@update:modelValue="$emit('update:modelValue', $event ? $event.id : null)" />
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { useLanguagesStore } from '../store.js'

/**
 * Pick the mail template a compose dialog starts from. The templates come
 * with the mail preview (built-in first, then the custom ones of that
 * type); nothing is shown when there is no choice.
 */
export default {
	name: 'TemplatePicker',
	components: { NcSelect },
	props: {
		/** [{ id, name, language, isDefault, builtin }] */
		templates: { type: Array, required: true },
		/** template id as a string; '' = the "each candidate's default" option */
		modelValue: { type: String, default: null },
		/** offer "the default template in each candidate's language" (bulk mail) */
		perCandidate: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],
	setup() {
		return { languages: useLanguagesStore() }
	},

	computed: {
		options() {
			const options = this.templates.map((template) => ({ id: String(template.id), label: this.label(template) }))
			if (this.perCandidate) {
				options.unshift({ id: '', label: this.t('recruiting', 'Default template, in each candidate’s language') })
			}
			return options
		},

		selected() {
			return this.options.find((option) => option.id === this.modelValue) ?? null
		},
	},

	created() {
		this.languages.load().catch(() => {
			// without names the language codes are shown
		})
	},

	methods: {
		label(template) {
			const parts = [template.name]
			if (template.language) {
				parts.push(this.languages.name(template.language))
			}
			if (template.isDefault) {
				parts.push(this.t('recruiting', 'default'))
			}
			return parts.join(' · ')
		},
	},
}
</script>
