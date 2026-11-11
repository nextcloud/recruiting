<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSelect
		:modelValue="selected"
		:options="languages.languages"
		:inputLabel="label"
		:placeholder="placeholder"
		label="name"
		:loading="!languages.loaded"
		@update:modelValue="$emit('update:modelValue', $event ? $event.code : null)" />
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { useLanguagesStore } from '../store.js'

/**
 * A language for candidate-facing mail (a candidate's or a template's).
 * Clearing it means "not set".
 */
export default {
	name: 'LanguageSelect',
	components: { NcSelect },
	props: {
		/** Nextcloud language code, or null */
		modelValue: { type: String, default: null },
		label: { type: String, required: true },
		placeholder: { type: String, default: '' },
	},

	emits: ['update:modelValue'],
	setup() {
		return { languages: useLanguagesStore() }
	},

	computed: {
		selected() {
			if (!this.modelValue) {
				return null
			}
			return this.languages.languages.find((language) => language.code === this.modelValue)
				?? { code: this.modelValue, name: this.modelValue }
		},
	},

	created() {
		this.languages.load().catch(() => {
			// the select stays empty; the field is optional
		})
	},
}
</script>
