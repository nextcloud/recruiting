<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="candidate.duplicateOf || others.length > 0" class="duplicate-hints">
		<NcNoteCard v-if="candidate.duplicateOf" type="warning">
			{{ t('recruiting', 'This person applied before — there is an earlier application on file.') }}
			<a href="#" @click.prevent="sidebar.open(candidate.duplicateOf)">{{ t('recruiting', 'Show it') }}</a>
			<div v-if="candidate.permissions.manage" class="duplicate-hints__actions">
				<NcButton
					variant="secondary"
					:disabled="merging"
					:title="t('recruiting', 'Moves documents, log, comments, votes, interviews and offers to the earlier application and deletes this one')"
					@click="merge">
					<template #icon>
						<CallMerge :size="20" />
					</template>
					{{ t('recruiting', 'Merge into the earlier application') }}
				</NcButton>
			</div>
		</NcNoteCard>

		<NcNoteCard v-if="others.length > 0" type="info">
			<strong>{{ t('recruiting', 'Also applied for') }}</strong>
			<ul class="duplicate-hints__list">
				<li v-for="other in others" :key="other.id">
					<a href="#" @click.prevent="sidebar.open(other.id)">{{ other.openingTitle ?? t('recruiting', 'Triage inbox') }}</a>
					<span class="duplicate-hints__meta">
						{{ t('recruiting', '{stage}, applied {date}', { stage: stageLabel(other.stage), date: formatDate(other.createdAt) }) }}
					</span>
				</li>
			</ul>
		</NcNoteCard>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import CallMerge from 'vue-material-design-icons/CallMerge.vue'
import api, { errorMessage } from '../../api.js'
import { useSidebarStore } from '../../store.js'
import { confirmDestructive } from '../../utils/confirm.js'
import { formatDate, stageLabel } from '../../utils/format.js'

export default {
	name: 'DuplicateHints',
	components: { CallMerge, NcButton, NcNoteCard },
	props: {
		candidate: { type: Object, required: true },
	},

	setup() {
		return { sidebar: useSidebarStore() }
	},

	data() {
		return { merging: false }
	},

	computed: {
		others() {
			return this.candidate.otherApplications ?? []
		},
	},

	methods: {
		formatDate,
		stageLabel,
		async merge() {
			if (!await confirmDestructive(
				this.t('recruiting', 'Merge into the earlier application?'),
				this.t('recruiting', 'Documents, log, comments, votes, interviews and offers of this application move to the earlier one, then this application is deleted. Where both have a vote or a screener from the same person, the earlier application’s is kept. This cannot be undone.'),
				this.t('recruiting', 'Merge'),
			)) {
				return
			}
			const targetId = this.candidate.duplicateOf
			this.merging = true
			try {
				await api.mergeCandidate(this.candidate.id, targetId)
				showSuccess(this.t('recruiting', 'Applications merged'))
				this.sidebar.touch()
				this.sidebar.open(targetId)
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not merge the applications')))
			} finally {
				this.merging = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.duplicate-hints {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&__actions {
		margin-top: calc(var(--default-grid-baseline) * 2);
	}

	&__list {
		margin: var(--default-grid-baseline) 0 0;
		padding: 0;
		list-style: none;

		li {
			display: flex;
			flex-wrap: wrap;
			gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
		}
	}

	&__meta {
		color: var(--color-text-maxcontrast);
	}
}
</style>
