<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('recruiting', 'Recruiting')"
		:description="t('recruiting', 'A summary mail with new applications in your openings, your pending reviews, upcoming interviews and offers awaiting your approval — only sent when there is something to report.')">
		<div class="personal-recruiting">
			<NcSelect
				v-model="mode"
				:options="options"
				:inputLabel="t('recruiting', 'Email digest')"
				label="label"
				:clearable="false"
				@update:modelValue="save" />
		</div>
	</NcSettingsSection>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import api, { errorMessage } from '../api.js'

export default {
	name: 'PersonalSettings',
	components: { NcSelect, NcSettingsSection },
	data() {
		return {
			mode: null,
		}
	},

	computed: {
		options() {
			return [
				{ id: 'none', label: this.t('recruiting', 'No digest') },
				{ id: 'daily', label: this.t('recruiting', 'Daily') },
				{ id: 'weekly', label: this.t('recruiting', 'Weekly') },
			]
		},
	},

	async created() {
		try {
			const { data } = await api.getPersonal()
			this.mode = this.options.find((option) => option.id === data.digest) ?? this.options[0]
		} catch {
			this.mode = this.options[0]
		}
	},

	methods: {
		async save(option) {
			if (!option) {
				return
			}
			try {
				await api.updatePersonal(option.id)
				showSuccess(this.t('recruiting', 'Saved'))
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not save the setting')))
			}
		},
	},
}
</script>

<style scoped>
.personal-recruiting {
	max-width: 400px;
}
</style>
