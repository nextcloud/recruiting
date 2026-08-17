<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="triage-view">
		<h2>{{ t('recruiting', 'Triage inbox') }}</h2>
		<p class="triage-view__hint">
			{{ t('recruiting', 'Applications that arrived by email without a matching opening. Open one to assign it to an opening — that moves the application into that opening’s “New” column and notifies its hiring managers; deleting removes the application and its documents permanently (spam). Tip: put the opening’s mail tag — e.g. jobs+backend-engineer@… — into your job postings and applications skip triage entirely.') }}
		</p>

		<NcNoteCard v-if="ingestion?.status?.error" type="warning">
			{{ t('recruiting', 'The last mailbox check failed {when}: {error}', {
				when: fromNow(ingestion.status.ranAt),
				error: ingestion.status.error,
			}) }}
		</NcNoteCard>
		<p v-else-if="ingestion?.enabled && ingestion?.status" class="triage-view__heartbeat">
			{{ t('recruiting', 'Mailbox checked {when} — {fetched} mails, {created} new', {
				when: fromNow(ingestion.status.ranAt),
				fetched: ingestion.status.fetched,
				created: ingestion.status.created,
			}) }}
		</p>

		<NcLoadingIcon v-if="loading" :size="44" class="triage-view__loading" />
		<NcEmptyContent
			v-else-if="candidates.length === 0"
			:name="t('recruiting', 'Inbox zero!')"
			:description="t('recruiting', 'Every application found its opening.')">
			<template #icon>
				<InboxArrowDown />
			</template>
		</NcEmptyContent>

		<div v-else class="triage-view__list">
			<CandidateCard
				v-for="candidate in candidates"
				:key="candidate.id"
				:candidate="candidate"
				:dense="true"
				@open="sidebar.open(candidate.id)">
				<template #actions>
					<div class="triage-view__actions" @click.stop>
						<NcActions :ariaLabel="t('recruiting', 'More actions')">
							<NcActionButton :closeAfterClick="true" @click="remove(candidate)">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('recruiting', 'Delete (spam)') }}
							</NcActionButton>
						</NcActions>
					</div>
				</template>
			</CandidateCard>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import InboxArrowDown from 'vue-material-design-icons/InboxArrowDown.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import api, { errorMessage } from '../api.js'
import { fromNow } from '../utils/format.js'
import { confirmDestructive } from '../utils/confirm.js'
import CandidateCard from '../components/CandidateCard.vue'
import { useSessionStore, useSidebarStore } from '../store.js'

export default {
	name: 'TriageView',
	components: {
		CandidateCard,
		InboxArrowDown,
		NcActionButton,
		NcActions,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		TrashCanOutline,
	},
	setup() {
		return {
			sidebar: useSidebarStore(),
			session: useSessionStore(),
		}
	},
	data() {
		return {
			candidates: [],
			ingestion: null,
			loading: true,
		}
	},
	watch: {
		'sidebar.version'() {
			this.load(true)
		},
	},
	created() {
		this.load()
		this.loadIngestionStatus()
	},
	methods: {
		fromNow,
		async loadIngestionStatus() {
			try {
				const { data } = await api.getIngestionStatus()
				this.ingestion = data
			} catch {
				// the heartbeat is informational — the inbox works without it
			}
		},
		async load(silent = false) {
			if (!silent) {
				this.loading = true
			}
			try {
				const { data } = await api.getTriage()
				this.candidates = data
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load the triage inbox')))
			} finally {
				this.loading = false
			}
		},
		async remove(candidate) {
			if (!await confirmDestructive(
				this.t('recruiting', 'Delete "{name}" permanently?', { name: candidate.displayName }),
				this.t('recruiting', 'The application and its documents are removed for good.'),
				this.t('recruiting', 'Delete permanently'),
			)) {
				return
			}
			try {
				await api.deleteCandidate(candidate.id)
				this.load(true)
				this.session.refresh()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not delete the candidate')))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.triage-view {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	max-width: 900px;

	h2 {
		margin: 0;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	&__heartbeat {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		margin: 0;
	}

	&__loading {
		margin-top: 15vh;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__actions {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
	}
}
</style>
