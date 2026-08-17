<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="opening-view">
		<div v-if="opening" class="opening-view__header">
			<div class="opening-view__title-row">
				<h2 class="opening-view__title">
					{{ opening.title }}
				</h2>
				<span v-if="opening.status === 'on_hold'" class="opening-view__status opening-view__status--hold">
					{{ t('recruiting', 'On hold') }}
				</span>
				<span v-else-if="opening.status === 'closed'" class="opening-view__status opening-view__status--closed">
					{{ t('recruiting', 'Closed') }}
				</span>
				<div class="opening-view__actions">
					<NcTextField
						v-model="filterQuery"
						class="opening-view__filter"
						:label="t('recruiting', 'Filter by name or email')"
						:showTrailingButton="filterQuery !== ''"
						trailingButtonIcon="close"
						@trailing-button-click="filterQuery = ''" />
					<NcButton
						v-if="attentionCount > 0"
						:variant="attentionOnly ? 'primary' : 'secondary'"
						:title="t('recruiting', 'Only show candidates where something is stuck: no votes, no interview booked, no active offer, or expiring offers')"
						@click="attentionOnly = !attentionOnly">
						⚠️ {{ t('recruiting', 'Stuck') }} · {{ attentionCount }}
					</NcButton>
					<NcButton
						v-if="canManage && opening.status === 'open'"
						variant="primary"
						:title="t('recruiting', 'Add a candidate manually — they start in the “New” column and the hiring managers are notified')"
						@click="showCandidateModal = true">
						<template #icon>
							<AccountPlusOutline :size="20" />
						</template>
						{{ t('recruiting', 'Add candidate') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:ariaLabel="view === 'board' ? t('recruiting', 'Show as table') : t('recruiting', 'Show as board')"
						:title="view === 'board' ? t('recruiting', 'Show as table') : t('recruiting', 'Show as board')"
						@click="view = view === 'board' ? 'table' : 'board'">
						<template #icon>
							<TableLarge v-if="view === 'board'" :size="20" />
							<ViewColumnOutline v-else :size="20" />
						</template>
					</NcButton>
					<a
						v-if="opening.talkUrl"
						class="opening-view__talk button-vue button-vue--vue-secondary"
						:href="opening.talkUrl"
						target="_blank"
						rel="noopener">
						💬 {{ t('recruiting', 'Team chat') }}
					</a>
					<NcActions v-if="canManage" :ariaLabel="t('recruiting', 'Opening actions')">
						<NcActionButton @click="showEditModal = true">
							<template #icon>
								<PencilOutline :size="20" />
							</template>
							{{ t('recruiting', 'Edit opening & team') }}
						</NcActionButton>
						<NcActionButton v-if="session.talkAvailable && !opening.talkUrl" @click="createTalkRoom">
							<template #icon>
								<ForumOutline :size="20" />
							</template>
							{{ t('recruiting', 'Create team chat (Talk)') }}
						</NcActionButton>
						<NcActionButton v-if="opening.talkUrl" @click="removeTalkRoom">
							<template #icon>
								<ForumOutline :size="20" />
							</template>
							{{ t('recruiting', 'Delete team chat') }}
						</NcActionButton>
						<NcActionButton v-if="opening.status === 'open'" @click="setStatus('on_hold')">
							<template #icon>
								<PauseCircleOutline :size="20" />
							</template>
							{{ t('recruiting', 'Put on hold') }}
						</NcActionButton>
						<NcActionButton v-if="opening.status !== 'open'" @click="setStatus('open')">
							<template #icon>
								<PlayCircleOutline :size="20" />
							</template>
							{{ t('recruiting', 'Reopen') }}
						</NcActionButton>
						<NcActionButton v-if="opening.status !== 'closed'" @click="closeOpening">
							<template #icon>
								<ArchiveOutline :size="20" />
							</template>
							{{ t('recruiting', 'Close opening') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>
			<div class="opening-view__meta">
				<span v-if="opening.department">🏢 {{ opening.department }}</span>
				<span v-if="opening.location">📍 {{ opening.location }}</span>
				<span v-if="opening.employmentType">🕐 {{ opening.employmentType }}</span>
				<span v-if="session.isRecruiter" class="opening-view__slug" :title="t('recruiting', 'Mails to your applications address with this tag are routed into this opening automatically')">
					✉️ [{{ opening.mailSlug }}]
				</span>
				<span class="opening-view__team">
					<NcAvatar
						v-for="member in opening.team"
						:key="member.uid"
						:user="member.uid"
						:displayName="member.displayName"
						:size="24"
						:title="member.displayName + ' — ' + roleLabel(member.role)" />
				</span>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" class="opening-view__loading" :size="44" />

		<template v-else-if="opening">
			<p v-if="view === 'board' && canManage && candidates.length > 0" class="opening-view__board-hint">
				{{ t('recruiting', 'Drag cards between stages or use a card’s ⋮ menu. Every move is logged on the candidate’s timeline and visible to the hiring team.') }}
			</p>
			<!-- Board -->
			<div v-if="view === 'board'" class="board">
				<section
					v-for="stage in session.stages"
					:key="stage"
					class="board__column"
					:class="[
						'board__column--' + stage,
						{
							'board__column--dropover': dropStage === stage,
							'board__column--droppable': dragged !== null && dragged.stage !== stage,
						},
					]"
					:aria-label="stageLabel(stage)"
					@dragover.prevent="onDragOver(stage, $event)"
					@dragleave="dropStage === stage && (dropStage = null)"
					@drop.prevent="onDrop(stage)">
					<header class="board__column-header">
						<span class="board__column-dot" aria-hidden="true" />
						<span class="board__column-title">{{ stageLabel(stage) }}</span>
						<span class="board__column-count">{{ stageTotal(stage) }}</span>
					</header>
					<TransitionGroup tag="div" name="card" class="board__cards">
						<CandidateCard
							v-for="candidate in byStage(stage)"
							:key="candidate.id"
							:candidate="candidate"
							:draggable="canManage"
							@open="openCandidate"
							@dragstart="onDragStart(candidate, $event)">
							<template v-if="canManage" #actions>
								<NcActions :ariaLabel="t('recruiting', 'Candidate actions')" @click.stop>
									<NcActionButton
										v-for="target in stageTargets(candidate)"
										:key="target"
										:closeAfterClick="true"
										@click="moveTo(candidate, target)">
										<template #icon>
											<ArrowRightThin :size="20" />
										</template>
										{{ t('recruiting', 'Move to "{stage}"', { stage: stageLabel(target) }) }}
									</NcActionButton>
									<NcActionSeparator />
									<NcActionButton :closeAfterClick="true" @click="rejectTarget = candidate">
										<template #icon>
											<CloseCircleOutline :size="20" />
										</template>
										{{ t('recruiting', 'Reject …') }}
									</NcActionButton>
								</NcActions>
							</template>
						</CandidateCard>
					</TransitionGroup>
					<p v-if="byStage(stage).length === 0" class="board__empty">
						{{ dragged !== null
							? t('recruiting', 'Drop the card here')
							: (filterQuery !== '' || attentionOnly) ? t('recruiting', 'No matches') : t('recruiting', 'No candidates') }}
					</p>
					<NcButton
						v-if="hasMore(stage)"
						class="board__more"
						variant="tertiary"
						:disabled="loadingMore"
						@click="loadMore(stage)">
						{{ t('recruiting', 'Show {count} more', { count: Math.min(pageSize, stageTotal(stage) - loadedInStage(stage)) }) }}
					</NcButton>
				</section>
			</div>

			<!-- Table -->
			<div v-else class="table-wrap">
				<div v-if="selection.size > 0" class="bulk-bar">
					<span class="bulk-bar__count">{{ n('recruiting', '%n selected', '%n selected', selection.size) }}</span>
					<NcActions :menuName="t('recruiting', 'Move to …')">
						<NcActionButton
							v-for="stage in allStages"
							:key="stage"
							:closeAfterClick="true"
							@click="bulkMove(stage)">
							<template #icon>
								<ArrowRightThin :size="20" />
							</template>
							{{ stageLabel(stage) }}
						</NcActionButton>
					</NcActions>
					<NcButton variant="error" @click="startBulkReject">
						{{ t('recruiting', 'Reject …') }}
					</NcButton>
					<NcButton variant="tertiary" @click="selection = new Set()">
						{{ t('recruiting', 'Clear selection') }}
					</NcButton>
				</div>
				<table class="candidate-table">
					<thead>
						<tr>
							<th v-if="canManage" class="candidate-table__check">
								<input
									type="checkbox"
									:checked="allVisibleSelected"
									:aria-label="t('recruiting', 'Select all')"
									@change="toggleSelectAll">
							</th>
							<th
								v-for="column in tableColumns"
								:key="column.key"
								:aria-sort="sortKey === column.key ? (sortAsc ? 'ascending' : 'descending') : 'none'">
								<button class="candidate-table__sort" @click="sortBy(column.key)">
									{{ column.label }}
									<span v-if="sortKey === column.key" aria-hidden="true">{{ sortAsc ? '▲' : '▼' }}</span>
								</button>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="candidate in sortedCandidates"
							:key="candidate.id"
							class="candidate-table__row"
							@click="openCandidate(candidate)">
							<td v-if="canManage" class="candidate-table__check" @click.stop>
								<input
									type="checkbox"
									:checked="selection.has(candidate.id)"
									:aria-label="t('recruiting', 'Select {name}', { name: candidate.displayName })"
									@change="toggleSelect(candidate.id)">
							</td>
							<td>
								<div class="candidate-table__person">
									<NcAvatar :displayName="candidate.displayName" :disableMenu="true" :size="28" />
									<div>
										<div>{{ candidate.displayName }}</div>
										<div class="candidate-table__email">{{ candidate.email }}</div>
									</div>
								</div>
							</td>
							<td>
								<span class="candidate-table__stage" :class="'candidate-table__stage--' + candidate.stage">
									{{ stageLabel(candidate.stage) }}
								</span>
							</td>
							<td>
								<template v-if="candidate.voteTally">
									<span v-if="candidate.voteTally.yes">👍{{ candidate.voteTally.yes }} </span>
									<span v-if="candidate.voteTally.maybe">🤔{{ candidate.voteTally.maybe }} </span>
									<span v-if="candidate.voteTally.no">👎{{ candidate.voteTally.no }}</span>
								</template>
								<span v-else-if="candidate.voteCount > 0">🗳️ {{ candidate.voteCount }}</span>
							</td>
							<td>{{ formatDate(candidate.createdAt) }}</td>
							<td>{{ fromNow(candidate.stageChangedAt) }}</td>
						</tr>
					</tbody>
				</table>
				<NcEmptyContent v-if="filteredCandidates.length === 0" :name="filterQuery !== '' ? t('recruiting', 'No matches') : t('recruiting', 'No candidates yet')">
					<template #icon>
						<AccountSearchOutline />
					</template>
				</NcEmptyContent>
				<div v-if="stagesWithMore.length > 0" class="table-wrap__more">
					<NcButton variant="tertiary" :disabled="loadingMore" @click="loadMoreAll">
						{{ t('recruiting', 'Show more candidates') }}
					</NcButton>
				</div>
			</div>

			<!-- Terminal summary -->
			<footer v-if="view === 'board'" class="opening-view__terminal">
				<button
					class="opening-view__terminal-chip"
					:title="t('recruiting', 'Candidates who accepted an offer — click to see them in the table view')"
					@click="view = 'table'">
					✅ {{ t('recruiting', 'Hired') }}: {{ opening.stageCounts.hired ?? 0 }}
				</button>
				<button
					class="opening-view__terminal-chip"
					:title="t('recruiting', 'Rejected candidates stay on file until the retention period ends — click to see them in the table view')"
					@click="view = 'table'">
					🚫 {{ t('recruiting', 'Rejected') }}: {{ opening.stageCounts.rejected ?? 0 }}
				</button>
				<button
					class="opening-view__terminal-chip"
					:title="t('recruiting', 'Candidates who withdrew their application — click to see them in the table view')"
					@click="view = 'table'">
					↩️ {{ t('recruiting', 'Withdrawn') }}: {{ opening.stageCounts.withdrawn ?? 0 }}
				</button>
			</footer>
		</template>

		<CandidateModal
			v-if="showCandidateModal"
			:openingId="Number(id)"
			@close="showCandidateModal = false"
			@saved="onCandidateSaved" />
		<OpeningModal
			v-if="showEditModal"
			:opening="opening"
			@close="showEditModal = false"
			@saved="onOpeningSaved" />
		<RejectModal
			v-if="rejectTarget"
			:candidate="rejectTarget"
			@close="abortReject"
			@rejected="onRejected" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountSearchOutline from 'vue-material-design-icons/AccountSearchOutline.vue'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import ArrowRightThin from 'vue-material-design-icons/ArrowRightThin.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import ForumOutline from 'vue-material-design-icons/ForumOutline.vue'
import PauseCircleOutline from 'vue-material-design-icons/PauseCircleOutline.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import TableLarge from 'vue-material-design-icons/TableLarge.vue'
import ViewColumnOutline from 'vue-material-design-icons/ViewColumnOutline.vue'
import api, { errorMessage } from '../api.js'
import { confirmDestructive } from '../utils/confirm.js'
import CandidateCard from '../components/CandidateCard.vue'
import CandidateModal from '../components/CandidateModal.vue'
import OpeningModal from '../components/OpeningModal.vue'
import RejectModal from '../components/RejectModal.vue'
import { useOpeningsStore, useSessionStore, useSidebarStore } from '../store.js'
import { formatDate, fromNow, roleLabel, stageLabel } from '../utils/format.js'

export default {
	name: 'OpeningView',
	components: {
		AccountPlusOutline,
		AccountSearchOutline,
		ArchiveOutline,
		ArrowRightThin,
		CandidateCard,
		CandidateModal,
		CloseCircleOutline,
		ForumOutline,
		NcActionButton,
		NcActionSeparator,
		NcActions,
		NcAvatar,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
		OpeningModal,
		PauseCircleOutline,
		PencilOutline,
		PlayCircleOutline,
		RejectModal,
		TableLarge,
		ViewColumnOutline,
	},
	props: {
		id: { type: String, required: true },
	},
	setup() {
		return {
			session: useSessionStore(),
			openingsStore: useOpeningsStore(),
			sidebar: useSidebarStore(),
		}
	},
	data() {
		return {
			candidates: [],
			loading: true,
			loadingMore: false,
			pageSize: 100,
			filterQuery: '',
			attentionOnly: false,
			selection: new Set(),
			sortKey: null,
			sortAsc: true,
			rejectQueue: [],
			view: 'board',
			dragged: null,
			dropStage: null,
			showCandidateModal: false,
			showEditModal: false,
			rejectTarget: null,
		}
	},
	computed: {
		opening() {
			return this.openingsStore.byId(this.id)
		},
		filteredCandidates() {
			const query = this.filterQuery.trim().toLowerCase()
			let result = this.candidates
			if (query !== '') {
				result = result.filter((candidate) =>
					candidate.displayName.toLowerCase().includes(query)
					|| (candidate.email ?? '').toLowerCase().includes(query))
			}
			if (this.attentionOnly) {
				result = result.filter((candidate) => (candidate.attention ?? []).length > 0)
			}
			return result
		},
		attentionCount() {
			return this.candidates.filter((candidate) => (candidate.attention ?? []).length > 0).length
		},
		allStages() {
			return this.session.stages.concat(['hired', 'withdrawn'])
		},
		tableColumns() {
			return [
				{ key: 'name', label: this.t('recruiting', 'Candidate') },
				{ key: 'stage', label: this.t('recruiting', 'Stage') },
				{ key: 'votes', label: this.t('recruiting', 'Votes') },
				{ key: 'applied', label: this.t('recruiting', 'Applied') },
				{ key: 'inStage', label: this.t('recruiting', 'In stage since') },
			]
		},
		sortedCandidates() {
			if (this.sortKey === null) {
				return this.filteredCandidates
			}
			const order = ['new', 'screening', 'interview', 'offer', 'hired', 'rejected', 'withdrawn']
			const value = {
				name: (c) => c.displayName.toLowerCase(),
				stage: (c) => order.indexOf(c.stage),
				votes: (c) => c.voteCount,
				applied: (c) => c.createdAt ?? '',
				inStage: (c) => c.stageChangedAt ?? '',
			}[this.sortKey]
			const sign = this.sortAsc ? 1 : -1
			return [...this.filteredCandidates].sort((a, b) => {
				const va = value(a)
				const vb = value(b)
				return (va < vb ? -1 : va > vb ? 1 : 0) * sign
			})
		},
		allVisibleSelected() {
			return this.sortedCandidates.length > 0
				&& this.sortedCandidates.every((candidate) => this.selection.has(candidate.id))
		},
		stagesWithMore() {
			return this.session.stages
				.concat(['hired', 'rejected', 'withdrawn'])
				.filter((stage) => this.hasMore(stage))
		},
		canManage() {
			return ['recruiter', 'manager'].includes(this.opening?.myRole)
		},
	},
	watch: {
		id: {
			immediate: true,
			handler() {
				this.load()
			},
		},
		// Sidebar mutations (votes, stage moves, mails …) refresh the list
		'sidebar.version'() {
			this.load(true)
		},
	},
	methods: {
		formatDate,
		fromNow,
		roleLabel,
		stageLabel,
		async load(silent = false) {
			if (!silent) {
				this.loading = true
			}
			try {
				const [{ data }] = await Promise.all([
					api.getCandidates(this.id),
					this.refreshOpening(),
				])
				this.candidates = data.cards
				this.pageSize = data.page
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load candidates')))
			} finally {
				this.loading = false
			}
		},
		async refreshOpening() {
			try {
				const { data } = await api.getOpening(this.id)
				this.openingsStore.upsert(data)
			} catch {
				// list data stays
			}
		},
		byStage(stage) {
			return this.filteredCandidates.filter((candidate) => candidate.stage === stage)
		},
		sortBy(key) {
			if (this.sortKey === key) {
				if (this.sortAsc) {
					this.sortAsc = false
				} else {
					// third click restores the server order
					this.sortKey = null
					this.sortAsc = true
				}
				return
			}
			this.sortKey = key
			this.sortAsc = true
		},
		toggleSelect(id) {
			const next = new Set(this.selection)
			if (next.has(id)) {
				next.delete(id)
			} else {
				next.add(id)
			}
			this.selection = next
		},
		toggleSelectAll() {
			this.selection = this.allVisibleSelected
				? new Set()
				: new Set(this.sortedCandidates.map((candidate) => candidate.id))
		},
		async bulkMove(stage) {
			const ids = [...this.selection]
			let failed = 0
			for (const id of ids) {
				try {
					await api.setStage(id, stage)
				} catch {
					failed++
				}
			}
			this.selection = new Set()
			this.load(true)
			if (failed > 0) {
				showError(this.n('recruiting', '%n candidate could not be moved', '%n candidates could not be moved', failed))
			} else {
				showSuccess(this.t('recruiting', 'Moved to "{stage}"', { stage: this.stageLabel(stage) }))
			}
		},
		// Bulk reject: one shared decision, but the mail preview stays per
		// candidate (spec §4.5 — nothing goes out unreviewed). The modal
		// walks through the queue one candidate at a time.
		startBulkReject() {
			this.rejectQueue = this.sortedCandidates
				.filter((candidate) => this.selection.has(candidate.id) && candidate.stage !== 'rejected')
			this.selection = new Set()
			this.rejectTarget = this.rejectQueue.shift() ?? null
		},
		abortReject() {
			this.rejectQueue = []
			this.rejectTarget = null
		},
		loadedInStage(stage) {
			return this.candidates.filter((candidate) => candidate.stage === stage).length
		},
		stageTotal(stage) {
			return this.opening?.stageCounts?.[stage] ?? this.loadedInStage(stage)
		},
		hasMore(stage) {
			// While filtering, pagination hints would be misleading: the filter
			// only searches what is loaded
			return this.filterQuery === '' && this.stageTotal(stage) > this.loadedInStage(stage)
		},
		async loadMore(stage) {
			this.loadingMore = true
			try {
				const { data } = await api.getCandidates(this.id, { stage, offset: this.loadedInStage(stage) })
				const known = new Set(this.candidates.map((candidate) => candidate.id))
				this.candidates.push(...data.cards.filter((candidate) => !known.has(candidate.id)))
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load more candidates')))
			} finally {
				this.loadingMore = false
			}
		},
		async loadMoreAll() {
			for (const stage of this.stagesWithMore) {
				await this.loadMore(stage)
			}
		},
		stageTargets(candidate) {
			return this.session.stages.filter((stage) => stage !== candidate.stage)
		},
		openCandidate(candidate) {
			this.sidebar.open(candidate.id)
		},
		onDragStart(candidate, event) {
			this.dragged = candidate
			event.dataTransfer.effectAllowed = 'move'
			event.dataTransfer.setData('text/plain', String(candidate.id))
		},
		onDragOver(stage, event) {
			if (this.dragged !== null && this.dragged.stage !== stage) {
				this.dropStage = stage
				event.dataTransfer.dropEffect = 'move'
			}
		},
		async onDrop(stage) {
			const candidate = this.dragged
			this.dragged = null
			this.dropStage = null
			if (candidate && candidate.stage !== stage) {
				await this.moveTo(candidate, stage)
			}
		},
		async moveTo(candidate, stage) {
			const previous = candidate.stage
			candidate.stage = stage // optimistic
			try {
				await api.setStage(candidate.id, stage)
				this.load(true)
			} catch (error) {
				candidate.stage = previous
				showError(errorMessage(error, this.t('recruiting', 'Could not move the candidate')))
			}
		},
		async createTalkRoom() {
			try {
				const { data } = await api.createTalkRoom(this.opening.id)
				this.openingsStore.upsert(data)
				showSuccess(this.t('recruiting', 'Team chat created — the hiring team was added'))
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not create the team chat')))
			}
		},
		async removeTalkRoom() {
			if (!await confirmDestructive(
				this.t('recruiting', 'Delete the team chat?'),
				this.t('recruiting', 'The Talk conversation and all its messages are deleted for the whole hiring team.'),
				this.t('recruiting', 'Delete team chat'),
			)) {
				return
			}
			try {
				const { data } = await api.removeTalkRoom(this.opening.id)
				this.openingsStore.upsert(data)
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not delete the team chat')))
			}
		},
		async setStatus(status) {
			try {
				const { data } = await api.setOpeningStatus(this.opening.id, status)
				this.openingsStore.upsert(data)
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not change the status')))
			}
		},
		async closeOpening() {
			const active = this.candidates.filter((c) => this.session.isActiveStage(c.stage))
			if (active.length > 0
				&& !await confirmDestructive(
					this.t('recruiting', 'Close this opening?'),
					this.n(
						'recruiting',
						'There is still %n active candidate. Remaining candidates keep their stage and can still be rejected afterwards.',
						'There are still %n active candidates. Remaining candidates keep their stage and can still be rejected afterwards.',
						active.length,
					),
					this.t('recruiting', 'Close opening'),
				)) {
				return
			}
			await this.setStatus('closed')
			showSuccess(this.t('recruiting', 'Opening closed'))
		},
		onCandidateSaved() {
			this.showCandidateModal = false
			this.load(true)
		},
		onOpeningSaved(opening) {
			this.showEditModal = false
			this.openingsStore.upsert(opening)
		},
		onRejected() {
			// Bulk reject walks a queue: on to the next candidate, if any
			this.rejectTarget = this.rejectQueue.shift() ?? null
			this.load(true)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../css/stages' as stages;

.opening-view {
	display: flex;
	flex-direction: column;
	height: 100%;
	padding: calc(var(--default-grid-baseline) * 4);
	gap: calc(var(--default-grid-baseline) * 3);

	&__header {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__title-row {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
	}

	&__title {
		margin: 0;
		font-size: 1.4em;
	}

	&__status {
		border-radius: var(--border-radius-pill);
		padding: 2px 10px;
		font-size: 0.85em;
		background-color: var(--color-background-hover);

		&--hold {
			background-color: var(--color-warning-hover, var(--color-background-hover));
		}
	}

	&__actions {
		margin-inline-start: auto;
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: var(--default-grid-baseline);

		// buttons keep their label; the filter is the only thing that shrinks
		> :not(.opening-view__filter) {
			flex-shrink: 0;
		}
	}

	&__filter {
		max-width: 200px;
		min-width: 130px;
	}

	&__talk {
		text-decoration: none;
		border-radius: var(--border-radius-element, 25px);
		padding: 6px 14px;
		background-color: var(--color-background-hover);
		border: 1px solid var(--color-border);
		white-space: nowrap;

		&:hover {
			background-color: var(--color-background-dark);
		}
	}

	&__meta {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 3);
		color: var(--color-text-maxcontrast);
		font-size: 0.95em;
	}

	&__slug {
		font-family: monospace;
	}

	&__team {
		display: flex;
		gap: 2px;
	}

	&__loading {
		margin-top: 20vh;
	}

	&__board-hint {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		margin: 0;
	}

	&__terminal {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__terminal-chip {
		background-color: var(--color-background-hover);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-pill);
		padding: 4px 12px;
		font-size: 0.9em;
		cursor: pointer;

		&:hover {
			background-color: var(--color-background-dark);
		}
	}
}

.board {
	@include stages.vars;

	display: grid;
	grid-template-columns: repeat(4, minmax(220px, 1fr));
	gap: calc(var(--default-grid-baseline) * 3);
	flex: 1;
	min-height: 0;
	overflow-x: auto;

	&__column {
		background-color: var(--color-background-hover);
		border-radius: var(--border-radius-container, var(--border-radius-large));
		display: flex;
		flex-direction: column;
		min-height: 0;
		border: 2px solid transparent;
		// every stage keeps its accent color (also used by the header dot)
		border-top: 3px solid var(--recruiting-stage-accent, var(--color-border));
		transition: border-color 0.15s ease;

		&--new {
			--recruiting-stage-accent: var(--recruiting-stage-new);
		}

		&--screening {
			--recruiting-stage-accent: var(--recruiting-stage-screening);
		}

		&--interview {
			--recruiting-stage-accent: var(--recruiting-stage-interview);
		}

		&--offer {
			--recruiting-stage-accent: var(--recruiting-stage-offer);
		}

		// a drag is in flight and this column is a valid target
		&--droppable {
			border-style: dashed;
			border-color: var(--color-border-maxcontrast, var(--color-border));
			border-top: 3px solid var(--recruiting-stage-accent, var(--color-border));
		}

		&--dropover {
			border-style: solid;
			border-color: var(--color-primary-element);
			border-top: 3px solid var(--recruiting-stage-accent, var(--color-border));
		}
	}

	&__column-header {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
		padding: calc(var(--default-grid-baseline) * 3);
		padding-bottom: var(--default-grid-baseline);
		font-weight: 600;
	}

	&__column-dot {
		width: 10px;
		height: 10px;
		border-radius: 50%;
		background-color: var(--recruiting-stage-accent, var(--color-border));
		flex: 0 0 auto;
	}

	&__column-count {
		background-color: var(--color-main-background);
		border-radius: var(--border-radius-pill);
		padding: 0 8px;
		font-size: 0.85em;
		color: var(--color-text-maxcontrast);
		margin-inline-start: auto;
	}

	&__cards {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) * 2);
		overflow-y: auto;
		flex: 1;

		// an empty column: let the empty message take the space instead
		&:empty {
			display: none;
		}
	}

	&__empty {
		color: var(--color-text-maxcontrast);
		text-align: center;
		padding: calc(var(--default-grid-baseline) * 4);
		font-size: 0.9em;
		flex: 1;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	&__more {
		align-self: center;
		margin-bottom: calc(var(--default-grid-baseline) * 2);
	}
}

// board cards glide instead of teleporting (TransitionGroup)
.card-move {
	transition: transform 0.25s ease;
}

.card-enter-active {
	transition: opacity 0.2s ease, transform 0.2s ease;
}

.card-enter-from {
	opacity: 0;
	transform: translateY(6px);
}

.bulk-bar {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	position: sticky;
	top: 0;
	z-index: 10;

	&__count {
		font-weight: 600;
		margin-inline-start: var(--default-grid-baseline);
	}
}

.table-wrap__more {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 2);
}

.table-wrap {
	overflow: auto;
	flex: 1;
}

.candidate-table {
	@include stages.vars;

	width: 100%;
	border-collapse: collapse;

	th {
		text-align: start;
		color: var(--color-text-maxcontrast);
		font-weight: 600;
		padding: calc(var(--default-grid-baseline) * 2);
		border-bottom: 1px solid var(--color-border);
	}

	&__row {
		cursor: pointer;

		&:hover {
			background-color: var(--color-background-hover);
		}

		td {
			padding: calc(var(--default-grid-baseline) * 2);
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__person {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__email {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}

	&__stage {
		border-radius: var(--border-radius-pill);
		padding: 2px 10px;
		font-size: 0.85em;
		background-color: var(--color-background-hover);

		// same accent per stage as the board columns
		&::before {
			content: '';
			display: inline-block;
			width: 8px;
			height: 8px;
			border-radius: 50%;
			background-color: var(--recruiting-stage-accent, transparent);
			margin-inline-end: 6px;
			vertical-align: baseline;
		}

		&--new {
			--recruiting-stage-accent: var(--recruiting-stage-new);
		}

		&--screening {
			--recruiting-stage-accent: var(--recruiting-stage-screening);
		}

		&--interview {
			--recruiting-stage-accent: var(--recruiting-stage-interview);
		}

		&--offer {
			--recruiting-stage-accent: var(--recruiting-stage-offer);
		}

		&--hired {
			--recruiting-stage-accent: var(--recruiting-stage-hired);

			background-color: var(--color-success-hover, var(--color-background-hover));
		}

		&--rejected,
		&--withdrawn {
			color: var(--color-text-maxcontrast);
		}

		&--rejected {
			--recruiting-stage-accent: var(--recruiting-stage-rejected);
		}

		&--withdrawn {
			--recruiting-stage-accent: var(--recruiting-stage-withdrawn);
		}
	}

	&__sort {
		background: none;
		border: none;
		padding: 0;
		font: inherit;
		font-weight: 600;
		color: inherit;
		cursor: pointer;
		white-space: nowrap;

		&:hover {
			color: var(--color-main-text);
		}
	}

	&__check {
		width: 32px;

		input {
			cursor: pointer;
		}
	}
}

@media (max-width: 1024px) {
	.board {
		grid-template-columns: repeat(4, minmax(240px, 280px));
	}
}
</style>
