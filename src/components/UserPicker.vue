<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- TODO: migrate to NcSelectUsers; NcSelect still supports these props in v9 -->
	<!-- eslint-disable @nextcloud/no-deprecated-library-props -->
	<NcSelect
		:modelValue="modelValue"
		:options="options"
		:multiple="multiple"
		:loading="loading"
		:placeholder="placeholder || t('recruiting', 'Search for people …')"
		:userSelect="true"
		label="displayName"
		:filterable="false"
		:closeOnSelect="!multiple"
		@search="onSearch"
		@update:modelValue="$emit('update:modelValue', $event)">
		<template #no-options>
			{{ t('recruiting', 'Start typing to search for people') }}
		</template>
	</NcSelect>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import api from '../api.js'

let searchTimer = null

export default {
	name: 'UserPicker',
	components: { NcSelect },
	props: {
		modelValue: { type: [Array, Object], default: null },
		multiple: { type: Boolean, default: false },
		placeholder: { type: String, default: '' },
	},

	emits: ['update:modelValue'],
	data() {
		return {
			options: [],
			loading: false,
		}
	},

	methods: {
		onSearch(query) {
			clearTimeout(searchTimer)
			if ((query ?? '').trim().length < 2) {
				this.options = []
				return
			}
			searchTimer = setTimeout(async () => {
				this.loading = true
				try {
					const { data } = await api.searchUsers(query)
					// NcSelect user options: id + displayName (+ user for avatar)
					this.options = data.map((user) => ({
						id: user.uid,
						user: user.uid,
						displayName: user.displayName,
					}))
				} finally {
					this.loading = false
				}
			}, 250)
		},
	},
}
</script>
