<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="referrals-view">
		<h2>{{ t('recruiting', 'Refer someone') }}</h2>
		<p class="referrals-view__hint">
			{{ t('recruiting', 'Know someone who would be great for one of our open positions? Put them forward here. The hiring team takes it from there; you will see the status of your referral, but not the details of the process.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="44" class="referrals-view__loading" />
		<template v-else>
			<NcEmptyContent
				v-if="openingOptions.length === 0"
				:name="t('recruiting', 'No open positions right now')"
				:description="t('recruiting', 'Referrals are possible as soon as a position is open.')">
				<template #icon>
					<AccountPlusOutline />
				</template>
			</NcEmptyContent>

			<form v-else class="referrals-view__form" @submit.prevent="submit">
				<NcSelect
					v-model="form.opening"
					:options="openingOptions"
					label="label"
					:inputLabel="t('recruiting', 'Position')"
					:placeholder="t('recruiting', 'Pick an open position …')"
					:clearable="false" />
				<NcTextField v-model="form.name" :label="t('recruiting', 'Full name')" :required="true" />
				<NcTextField
					v-model="form.email"
					type="email"
					:label="t('recruiting', 'Email address')"
					:required="true" />
				<NcTextField v-model="form.phone" type="tel" :label="t('recruiting', 'Phone (optional)')" />
				<NcTextArea
					v-model="form.note"
					:label="t('recruiting', 'Why do you recommend this person?')"
					rows="4"
					resize="vertical"
					maxlength="5000" />
				<label class="referrals-view__file">
					<span>{{ t('recruiting', 'CV (optional)') }}</span>
					<input ref="cv" type="file" accept=".pdf,.doc,.docx,.odt,.txt,.md,.png,.jpg,.jpeg,.webp">
				</label>
				<NcCheckboxRadioSwitch v-model="form.consent">
					{{ t('recruiting', 'This person agreed to be referred') }}
				</NcCheckboxRadioSwitch>
				<div>
					<NcButton variant="primary" type="submit" :disabled="!canSubmit || saving">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
							<AccountPlusOutline v-else :size="20" />
						</template>
						{{ t('recruiting', 'Send referral') }}
					</NcButton>
				</div>
			</form>

			<section class="referrals-view__mine">
				<h3>{{ t('recruiting', 'Your referrals') }}</h3>
				<p v-if="referrals.length === 0" class="referrals-view__hint">
					{{ t('recruiting', 'You have not referred anyone yet.') }}
				</p>
				<ul v-else class="referrals-view__list">
					<li v-for="referral in referrals" :key="referral.id" class="referral">
						<div class="referral__info">
							<strong>{{ referral.name }}</strong>
							<span class="referral__meta">
								{{ referral.openingTitle }} · {{ t('recruiting', 'referred {when}', { when: fromNow(referral.createdAt) }) }}
							</span>
						</div>
						<span class="referral__status" :class="'referral__status--' + referral.status">
							{{ coarseStatusLabel(referral.status) }}
						</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import api, { errorMessage } from '../api.js'
import { coarseStatusLabel, fromNow } from '../utils/format.js'

/**
 *
 */
function emptyForm() {
	return {
		opening: null,
		name: '',
		email: '',
		phone: '',
		note: '',
		consent: false,
	}
}

export default {
	name: 'ReferralsView',
	components: {
		AccountPlusOutline,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			saving: false,
			openings: [],
			referrals: [],
			form: emptyForm(),
		}
	},

	computed: {
		openingOptions() {
			return this.openings.map((opening) => ({ id: opening.id, label: opening.title }))
		},

		canSubmit() {
			return this.form.opening !== null
				&& this.form.name.trim() !== ''
				&& this.form.email.trim() !== ''
				&& this.form.note.trim() !== ''
				&& this.form.consent
		},
	},

	created() {
		this.load()
	},

	methods: {
		coarseStatusLabel,
		fromNow,

		async load() {
			this.loading = true
			try {
				const [{ data: openings }, { data: referrals }] = await Promise.all([api.getReferralOpenings(), api.getReferrals()])
				this.openings = openings
				this.referrals = referrals
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load referrals')))
			} finally {
				this.loading = false
			}
		},

		async submit() {
			if (!this.canSubmit) {
				return
			}
			const form = new FormData()
			form.append('openingId', String(this.form.opening.id))
			form.append('name', this.form.name.trim())
			form.append('email', this.form.email.trim())
			form.append('phone', this.form.phone.trim())
			form.append('note', this.form.note.trim())
			form.append('consent', this.form.consent ? '1' : '0')
			const file = this.$refs.cv?.files?.[0]
			if (file) {
				form.append('cv', file, file.name)
			}
			this.saving = true
			try {
				const { data } = await api.createReferral(form)
				this.referrals.unshift(data)
				this.form = emptyForm()
				if (this.$refs.cv) {
					this.$refs.cv.value = ''
				}
				showSuccess(this.t('recruiting', 'Thank you! {name} has been referred.', { name: data.name }))
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not send the referral')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.referrals-view {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	max-width: 700px;

	h2,
	h3 {
		margin: 0;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	&__loading {
		margin-top: 15vh;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__file {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__mine {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		border-top: 1px solid var(--color-border);
		padding-top: calc(var(--default-grid-baseline) * 3);
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}

.referral {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3);

	&__info {
		flex: 1;
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__status {
		border-radius: var(--border-radius-pill);
		padding: 2px 10px;
		font-size: 0.85em;
		background-color: var(--color-background-hover);

		&--interview,
		&--offer {
			background-color: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}
	}
}
</style>
