<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal :name="title" size="normal" @close="$emit('close')">
		<div class="opening-modal">
			<h2>{{ title }}</h2>

			<NcTextField
				v-model="form.title"
				:label="t('recruiting', 'Job title')"
				:placeholder="t('recruiting', 'e.g. Senior Backend Engineer')"
				required />
			<p v-if="!opening" class="opening-modal__hint">
				{{ t('recruiting', 'A mail tag is generated from the title: applications sent to your applications mailbox as jobs+tag@… (or with [tag] in the subject) land directly in this opening — everything else goes to the triage inbox.') }}
			</p>

			<div class="opening-modal__row">
				<NcTextField v-model="form.department" :label="t('recruiting', 'Department')" />
				<NcTextField v-model="form.location" :label="t('recruiting', 'Location')" />
				<NcTextField v-model="form.employmentType" :label="t('recruiting', 'Employment type')" :placeholder="t('recruiting', 'e.g. Full-time')" />
			</div>

			<NcTextArea
				v-model="form.description"
				:label="t('recruiting', 'Description')"
				:placeholder="t('recruiting', 'What is this role about?')"
				resize="vertical" />
			<NcTextArea
				v-model="form.requirements"
				:label="t('recruiting', 'Requirements')"
				:placeholder="t('recruiting', 'Skills and experience you are looking for — the AI screening hints and interview question suggestions are grounded in this text')"
				resize="vertical" />

			<NcCheckboxRadioSwitch v-model="form.autoAck" type="switch">
				{{ t('recruiting', 'Send an automatic confirmation mail to candidates applying by email') }}
			</NcCheckboxRadioSwitch>

			<h3>{{ t('recruiting', 'Hiring team') }}</h3>
			<p class="opening-modal__hint">
				{{ t('recruiting', 'Hiring managers run the process, interviewers only see candidates assigned to them, observers can watch but not act. Recruiters always have access.') }}
			</p>

			<div v-for="(member, index) in form.team" :key="index" class="opening-modal__member">
				<UserPicker
					v-model="member.user"
					class="opening-modal__member-picker"
					:placeholder="t('recruiting', 'Pick a person …')" />
				<NcSelect
					v-model="member.role"
					class="opening-modal__member-role"
					:options="roleOptions"
					label="label"
					:clearable="false" />
				<NcButton
					variant="tertiary"
					:ariaLabel="t('recruiting', 'Remove from team')"
					@click="form.team.splice(index, 1)">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</div>
			<NcButton variant="tertiary" @click="addMember">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('recruiting', 'Add team member') }}
			</NcButton>

			<div class="opening-modal__buttons">
				<NcButton @click="$emit('close')">
					{{ t('recruiting', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving || !form.title.trim()" @click="save">
					{{ opening ? t('recruiting', 'Save') : t('recruiting', 'Create opening') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import UserPicker from './UserPicker.vue'
import api, { errorMessage } from '../api.js'
import { roleLabel } from '../utils/format.js'

export default {
	name: 'OpeningModal',
	components: {
		Close,
		NcButton,
		NcCheckboxRadioSwitch,
		NcModal,
		NcSelect,
		NcTextArea,
		NcTextField,
		Plus,
		UserPicker,
	},

	props: {
		opening: { type: Object, default: null },
	},

	emits: ['close', 'saved'],
	data() {
		return {
			saving: false,
			form: {
				title: this.opening?.title ?? '',
				department: this.opening?.department ?? '',
				location: this.opening?.location ?? '',
				employmentType: this.opening?.employmentType ?? '',
				description: this.opening?.description ?? '',
				requirements: this.opening?.requirements ?? '',
				autoAck: this.opening?.autoAck ?? true,
				team: (this.opening?.team ?? []).map((member) => ({
					user: { id: member.uid, user: member.uid, displayName: member.displayName },
					role: { id: member.role, label: roleLabel(member.role) },
				})),
			},
		}
	},

	computed: {
		title() {
			return this.opening
				? this.t('recruiting', 'Edit opening')
				: this.t('recruiting', 'New job opening')
		},

		roleOptions() {
			return ['manager', 'interviewer', 'observer'].map((role) => ({ id: role, label: roleLabel(role) }))
		},
	},

	methods: {
		addMember() {
			this.form.team.push({ user: null, role: this.roleOptions[1] })
		},

		async save() {
			this.saving = true
			try {
				const payload = {
					...this.form,
					team: this.form.team
						.filter((member) => member.user?.id)
						.map((member) => ({ uid: member.user.id, role: member.role.id })),
				}
				const { data } = this.opening
					? await api.updateOpening(this.opening.id, payload)
					: await api.createOpening(payload)
				this.$emit('saved', data)
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not save the opening')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.opening-modal {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);

	h2,
	h3 {
		margin: 0;
	}

	&__row {
		display: grid;
		grid-template-columns: 1fr 1fr 1fr;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	&__member {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__member-picker {
		flex: 2;
	}

	&__member-role {
		flex: 1;
		min-width: 150px;
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
		margin-top: calc(var(--default-grid-baseline) * 2);
	}

	@media (max-width: 640px) {
		&__row {
			grid-template-columns: 1fr;
		}
	}
}
</style>
