<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="profile-tab">
		<NcNoteCard v-if="candidate.duplicateOf" type="warning">
			{{ t('recruiting', 'This person applied before — there is an earlier application on file.') }}
			<a href="#" @click.prevent="sidebar.open(candidate.duplicateOf)">{{ t('recruiting', 'Show it') }}</a>
		</NcNoteCard>

		<NcNoteCard v-if="candidate.poolMember" type="success">
			{{ t('recruiting', 'Talent pool member since {date} — consented to being considered for future openings.', { date: formatDateTime(candidate.poolConsentAt) }) }}
		</NcNoteCard>

		<NcNoteCard v-if="candidate.aiSummary" type="info">
			<strong>{{ t('recruiting', 'AI summary — verify yourself') }}</strong><br>
			{{ candidate.aiSummary }}
		</NcNoteCard>
		<AiAssist
			v-if="candidate.permissions.manage"
			:candidateId="candidate.id"
			:action="'summary'"
			:label="candidate.aiSummary ? t('recruiting', 'Regenerate AI summary') : t('recruiting', 'Generate AI summary')"
			@result="sidebar.reload()" />

		<template v-if="editing">
			<NcTextField v-model="form.displayName" :label="t('recruiting', 'Full name')" />
			<NcTextField v-model="form.email" type="email" :label="t('recruiting', 'Email address')" />
			<NcTextField v-model="form.phone" type="tel" :label="t('recruiting', 'Phone')" />
			<div class="profile-tab__buttons">
				<NcButton @click="editing = false">
					{{ t('recruiting', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ t('recruiting', 'Save') }}
				</NcButton>
			</div>
		</template>

		<dl v-else class="profile-tab__facts">
			<div v-if="candidate.email">
				<dt>{{ t('recruiting', 'Email') }}</dt>
				<dd><a :href="'mailto:' + candidate.email">{{ candidate.email }}</a></dd>
			</div>
			<div v-if="candidate.phone">
				<dt>{{ t('recruiting', 'Phone') }}</dt>
				<dd><a :href="'tel:' + candidate.phone">{{ candidate.phone }}</a></dd>
			</div>
			<div>
				<dt>{{ t('recruiting', 'Source') }}</dt>
				<dd>{{ sourceLabel(candidate.source) }}</dd>
			</div>
			<div>
				<dt>{{ t('recruiting', 'Applied') }}</dt>
				<dd>{{ formatDateTime(candidate.createdAt) }}</dd>
			</div>
			<div v-if="candidate.openingTitle">
				<dt>{{ t('recruiting', 'Opening') }}</dt>
				<dd>{{ candidate.openingTitle }}</dd>
			</div>
		</dl>

		<NcButton v-if="!editing && candidate.permissions.manage" variant="tertiary" @click="startEdit">
			<template #icon>
				<PencilOutline :size="20" />
			</template>
			{{ t('recruiting', 'Edit contact details') }}
		</NcButton>

		<DocumentsSection :candidate="candidate" />

		<!-- Triage: assign to an opening -->
		<div v-if="candidate.openingId === null && session.isRecruiter" class="profile-tab__assign">
			<h3>{{ t('recruiting', 'Assign to an opening') }}</h3>
			<NcSelect
				v-model="assignTarget"
				:options="openingOptions"
				label="label"
				:placeholder="t('recruiting', 'Pick an opening …')" />
			<NcButton variant="primary" :disabled="!assignTarget" @click="assign">
				{{ t('recruiting', 'Assign') }}
			</NcButton>
		</div>

		<div v-if="canReject" class="profile-tab__reject">
			<NcButton
				variant="error"
				:title="t('recruiting', 'Ends the application: you pick a reason and can send the rejection mail from here')"
				@click="$emit('reject')">
				<template #icon>
					<CloseCircleOutline :size="20" />
				</template>
				{{ t('recruiting', 'Reject …') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import api, { errorMessage } from '../../api.js'
import { useOpeningsStore, useSessionStore, useSidebarStore } from '../../store.js'
import { formatDateTime, sourceLabel } from '../../utils/format.js'
import AiAssist from '../AiAssist.vue'
import DocumentsSection from './DocumentsSection.vue'

export default {
	name: 'ProfileTab',
	components: { AiAssist, CloseCircleOutline, DocumentsSection, NcButton, NcNoteCard, NcSelect, NcTextField, PencilOutline },
	props: {
		candidate: { type: Object, required: true },
	},
	emits: ['reject'],
	setup() {
		return {
			sidebar: useSidebarStore(),
			session: useSessionStore(),
			openings: useOpeningsStore(),
		}
	},
	data() {
		return {
			editing: false,
			saving: false,
			form: { displayName: '', email: '', phone: '' },
			assignTarget: null,
		}
	},
	computed: {
		canReject() {
			return this.candidate.permissions.manage
				&& this.candidate.stage !== 'rejected'
				&& !this.session.isTerminalStage(this.candidate.stage)
		},
		openingOptions() {
			return this.openings.open.map((opening) => ({ id: opening.id, label: opening.title }))
		},
	},
	methods: {
		formatDateTime,
		sourceLabel,
		startEdit() {
			this.form = {
				displayName: this.candidate.displayName,
				email: this.candidate.email,
				phone: this.candidate.phone,
			}
			this.editing = true
		},
		async save() {
			this.saving = true
			try {
				await api.updateCandidate(this.candidate.id, this.form)
				this.editing = false
				this.sidebar.reload()
				this.sidebar.touch()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not save the changes')))
			} finally {
				this.saving = false
			}
		},
		async assign() {
			try {
				await api.assignOpening(this.candidate.id, this.assignTarget.id)
				showSuccess(this.t('recruiting', 'Candidate assigned to "{title}"', { title: this.assignTarget.label }))
				this.sidebar.reload()
				this.sidebar.touch()
				this.session.refresh()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not assign the candidate')))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.profile-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding-block: calc(var(--default-grid-baseline) * 2);

	&__facts {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);

		div {
			display: grid;
			grid-template-columns: 110px 1fr;
			gap: calc(var(--default-grid-baseline) * 2);
		}

		dt {
			color: var(--color-text-maxcontrast);
			// the server stylesheet right-aligns dt, which glues the label
			// to its value in a two-column grid
			text-align: start;
		}

		dd {
			overflow-wrap: anywhere;
		}
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__reject {
		border-top: 1px solid var(--color-border);
		padding-top: calc(var(--default-grid-baseline) * 3);
	}

	&__assign {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		border-top: 1px solid var(--color-border);
		padding-top: calc(var(--default-grid-baseline) * 3);

		h3 {
			margin: 0;
		}
	}
}
</style>
