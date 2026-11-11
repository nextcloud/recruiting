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
						@trailingButtonClick="filterQuery = ''" />
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
						<NcActionButton
							v-if="session.isRecruiter && totalCount === 0"
							:title="t('recruiting', 'Only possible while the opening has no candidates at all')"
							@click="deleteOpening">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							{{ t('recruiting', 'Delete opening') }}
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
			<div v-if="view === 'board'" class="board" :style="{ '--board-columns': boardStages.length }">
				<section
					v-for="stage in boardStages"
					:key="stage"
					class="board__column"
					:class="[
						'board__column--' + stage,
						{
							'board__column--dropover': dropStage === stage,
							'board__column--droppable': dragged !== null && dragged.stage !== stage && session.isActiveStage(stage),
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
						<NcButton
							v-if="session.isTerminalStage(stage)"
							variant="tertiary"
							size="small"
							:ariaLabel="t('recruiting', 'Collapse “{stage}”', { stage: stageLabel(stage) })"
							:title="t('recruiting', 'Collapse “{stage}”', { stage: stageLabel(stage) })"
							@click="toggleTerminal(stage)">
							<template #icon>
								<ChevronLeft :size="20" />
							</template>
						</NcButton>
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
									<NcActionSeparator v-if="candidate.stage !== 'rejected'" />
									<NcActionButton v-if="candidate.stage !== 'rejected'" :closeAfterClick="true" @click="rejectTarget = candidate">
										<template #icon>
											<CloseCircleOutline :size="20" />
										</template>
										{{ t('recruiting', 'Reject …') }}
									</NcActionButton>
								</NcActions>
							</template>
						</CandidateCard>
					</TransitionGroup>
					<NcLoadingIcon v-if="byStage(stage).length === 0 && loadingStage === stage" class="board__empty" :size="28" />
					<p v-else-if="byStage(stage).length === 0" class="board__empty">
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
					<NcActions :menuName="t('recruiting', 'Move to …')">
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
						{{ t('recruiting', 'Reject …') }}
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
										<div class="candidate-table__email">
											{{ candidate.email }}
										</div>
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

			<!-- Terminal stages: collapsed to their count, cards loaded on expand -->
			<footer class="opening-view__terminal">
				<button
					v-for="chip in terminalChips"
					:key="chip.stage"
					class="opening-view__terminal-chip"
					:class="{ 'opening-view__terminal-chip--expanded': expanded.has(chip.stage) }"
					:aria-pressed="expanded.has(chip.stage) ? 'true' : 'false'"
					:title="chip.title"
					@click="toggleTerminal(chip.stage)">
					{{ chip.icon }} {{ stageLabel(chip.stage) }}: {{ stageTotal(chip.stage) }}
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
			@close="rejectTarget = null"
			@rejected="onRejected" />
		<BulkRejectModal
			v-if="bulkReject"
			:candidates="bulkReject.candidates"
			:presetReason="bulkReject.presetReason"
			:intro="bulkReject.intro"
			@finished="onBulkRejected"
			@close="bulkReject = null" />
		<CloseOpeningDialog
			v-if="showCloseDialog"
			:activeCount="opening?.activeCount ?? 0"
			@cancel="showCloseDialog = false"
			@closeOnly="closeWithoutChanges"
			@reject="closeWithRejection" />
	</div>
</template>

<script>
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountSearchOutline from 'vue-material-design-icons/AccountSearchOutline.vue'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import ArrowRightThin from 'vue-material-design-icons/ArrowRightThin.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import ForumOutline from 'vue-material-design-icons/ForumOutline.vue'
import PauseCircleOutline from 'vue-material-design-icons/PauseCircleOutline.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import TableLarge from 'vue-material-design-icons/TableLarge.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import ViewColumnOutline from 'vue-material-design-icons/ViewColumnOutline.vue'
import BulkRejectModal from '../components/BulkRejectModal.vue'
import CandidateCard from '../components/CandidateCard.vue'
import CandidateModal from '../components/CandidateModal.vue'
import CloseOpeningDialog from '../components/CloseOpeningDialog.vue'
import OpeningModal from '../components/OpeningModal.vue'
import RejectModal from '../components/RejectModal.vue'
import api, { errorMessage } from '../api.js'
import { useOpeningsStore, useSessionStore, useSidebarStore } from '../store.js'
import { confirmDestructive } from '../utils/confirm.js'
import { formatDate, fromNow, roleLabel, stageLabel } from '../utils/format.js'
import { handleStageConflict, isStageConflict } from '../utils/stageConflict.js'

/** How often an open board asks whether someone else changed it */
const POLL_INTERVAL = 30000

/**
 * @param {Array} list cards
 * @param {string} stage stage to count
 * @return {number}
 */
const countInStage = (list, stage) => list.filter((candidate) => candidate.stage === stage).length

export default {
	name: 'OpeningView',
	components: {
		AccountPlusOutline,
		AccountSearchOutline,
		ArchiveOutline,
		ArrowRightThin,
		BulkRejectModal,
		CandidateCard,
		CandidateModal,
		ChevronLeft,
		CloseCircleOutline,
		CloseOpeningDialog,
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
		TrashCanOutline,
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
			loadingStage: null,
			loadToken: 0,
			pageSize: 100,
			// interviewers get their whole list at once: nothing to page
			complete: false,
			// terminal stages whose cards are shown (loaded on demand)
			expanded: new Set(),
			revision: null,
			pollTimer: null,
			filterQuery: '',
			attentionOnly: false,
			selection: new Set(),
			sortKey: null,
			sortAsc: true,
			view: 'board',
			dragged: null,
			dropStage: null,
			showCandidateModal: false,
			showEditModal: false,
			rejectTarget: null,
			bulkReject: null,
			showCloseDialog: false,
		}
	},

	computed: {
		opening() {
			return this.openingsStore.byId(this.id)
		},

		visibleCandidates() {
			return this.candidates.filter((candidate) => this.session.isActiveStage(candidate.stage) || this.expanded.has(candidate.stage))
		},

		filteredCandidates() {
			const query = this.filterQuery.trim().toLowerCase()
			let result = this.visibleCandidates
			if (query !== '') {
				result = result.filter((candidate) => candidate.displayName.toLowerCase().includes(query)
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

		boardStages() {
			return this.session.stages.concat(this.session.terminalStages.filter((stage) => this.expanded.has(stage)))
		},

		terminalChips() {
			return [
				{ stage: 'hired', icon: '✅', title: this.t('recruiting', 'Candidates who accepted an offer — click to show or hide them') },
				{ stage: 'rejected', icon: '🚫', title: this.t('recruiting', 'Rejected candidates stay on file until the retention period ends — click to show or hide them') },
				{ stage: 'withdrawn', icon: '↩️', title: this.t('recruiting', 'Candidates who withdrew their application — click to show or hide them') },
			]
		},

		totalCount() {
			return Object.values(this.opening?.stageCounts ?? {}).reduce((sum, count) => sum + count, 0)
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
			return this.boardStages.filter((stage) => this.hasMore(stage))
		},

		canManage() {
			return ['recruiter', 'manager'].includes(this.opening?.myRole)
		},
	},

	watch: {
		id: {
			immediate: true,
			handler() {
				this.candidates = []
				this.expanded = new Set()
				this.selection = new Set()
				this.revision = null
				this.load()
			},
		},

		// Sidebar mutations (votes, stage moves, mails …): a change to one
		// candidate refreshes that card, anything else the whole board
		'sidebar.version': function() {
			const candidateId = this.sidebar.changedCandidateId
			if (candidateId === null) {
				this.load(true)
			} else {
				this.patchCard(candidateId)
			}
		},
	},

	mounted() {
		document.addEventListener('visibilitychange', this.onVisibilityChange)
		this.startPolling()
	},

	beforeUnmount() {
		this.stopPolling()
		document.removeEventListener('visibilitychange', this.onVisibilityChange)
	},

	methods: {
		formatDate,
		fromNow,
		roleLabel,
		stageLabel,
		/**
		 * (Re)load the board. A silent reload keeps what the user looks at:
		 * every stage is refilled to as many cards as were loaded before, and
		 * expanded terminal stages stay expanded — so the scroll position, the
		 * selection and the open sidebar survive.
		 *
		 * @param {boolean} silent reload in place, without the spinner
		 */
		async load(silent = false) {
			const token = ++this.loadToken
			const previous = silent ? this.loadedCounts() : {}
			if (!silent) {
				this.loading = true
			}
			try {
				// The marker is read before the cards, so a change in between
				// shows up on the next poll instead of being missed
				const revision = await this.fetchRevision()
				const [{ data }] = await Promise.all([
					api.getCandidates(this.id),
					this.refreshOpening(),
				])
				if (token !== this.loadToken) {
					return
				}
				this.pageSize = data.page
				this.complete = data.complete === true
				// Refilled before it replaces the shown list, so nothing jumps
				const cards = data.cards
				await this.fillStages(cards, previous, token)
				if (token !== this.loadToken) {
					return
				}
				this.candidates = cards
				this.revision = revision
				this.pruneSelection()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load candidates')))
			} finally {
				if (token === this.loadToken) {
					this.loading = false
				}
			}
		},

		loadedCounts() {
			return Object.fromEntries(this.boardStages.map((stage) => [stage, this.loadedInStage(stage)]))
		},

		/**
		 * @param {Array} list cards to fill up
		 * @param {object} previous stage => number of cards loaded before
		 * @param {number} token load the fill belongs to
		 */
		async fillStages(list, previous, token) {
			for (const stage of this.boardStages) {
				const wanted = Math.max(previous[stage] ?? 0, this.expanded.has(stage) ? 1 : 0)
				const target = Math.min(wanted, this.stageTotal(stage))
				while (!this.complete && token === this.loadToken && countInStage(list, stage) < target) {
					if (await this.fetchPage(stage, list) === 0) {
						break
					}
				}
			}
		},

		/**
		 * Append the next page of one stage.
		 *
		 * @param {string} stage the stage to page in
		 * @param {Array} list cards to append to
		 * @return {Promise<number>} number of cards that were new
		 */
		async fetchPage(stage, list = this.candidates) {
			const { data } = await api.getCandidates(this.id, { stage, offset: countInStage(list, stage) })
			const known = new Set(list.map((candidate) => candidate.id))
			const fresh = data.cards.filter((candidate) => !known.has(candidate.id))
			list.push(...fresh)
			return fresh.length
		},

		/**
		 * Refresh the one card a change of our own touched, plus the stage
		 * counts, instead of reloading the board. The board takes over the
		 * revision read here, so our own change causes no full reload; a
		 * change someone else made since the last poll shows up with the
		 * next revision change instead. A card that moved into a stage whose
		 * cards are only partly loaded cannot be placed in order; the board
		 * is reloaded instead.
		 *
		 * @param {number} candidateId the changed candidate
		 * @param {string|null} shownStage the stage the card was loaded in, when it was moved optimistically since
		 */
		async patchCard(candidateId, shownStage = null) {
			const known = this.candidates.find((candidate) => candidate.id === candidateId)
			if (this.loading || !known) {
				return
			}
			const fromStage = shownStage ?? known.stage
			const token = this.loadToken
			try {
				// Read before the card, as in load()
				const revision = await this.fetchRevision()
				const [card] = await Promise.all([
					this.fetchCard(candidateId),
					this.refreshOpening(),
				])
				if (token !== this.loadToken) {
					return
				}
				const others = this.candidates.filter((candidate) => candidate.id !== candidateId)
				if (card === null || card.openingId !== Number(this.id)) {
					this.candidates = others
				} else if (card.stage === fromStage || this.complete) {
					this.candidates = this.candidates.map((candidate) => candidate.id === candidateId ? card : candidate)
				} else if (!this.boardStages.includes(card.stage)) {
					// a collapsed stage loads its cards when it is expanded
					this.candidates = others
				} else if (countInStage(others, card.stage) + 1 >= this.stageTotal(card.stage)) {
					const next = others.findIndex((candidate) => candidate.stage === card.stage
						&& (candidate.createdAt > card.createdAt || (candidate.createdAt === card.createdAt && candidate.id > card.id)))
					others.splice(next === -1 ? others.length : next, 0, card)
					this.candidates = others
				} else {
					this.load(true)
					return
				}
				if (revision !== null && this.revision !== null) {
					this.revision = revision
				}
				this.pruneSelection()
			} catch {
				this.load(true)
			}
		},

		/**
		 * @param {number} candidateId the candidate
		 * @return {Promise<object|null>} the card, or null when it is gone or no longer visible
		 */
		async fetchCard(candidateId) {
			try {
				const { data } = await api.getCandidateCard(candidateId)
				return data
			} catch (error) {
				if ([403, 404].includes(error?.response?.status)) {
					return null
				}
				throw error
			}
		},

		async fetchRevision() {
			try {
				const { data } = await api.getOpeningRevision(this.id)
				return data.revision
			} catch {
				return null
			}
		},

		startPolling() {
			this.stopPolling()
			if (document.visibilityState === 'visible') {
				this.pollTimer = setInterval(this.poll, POLL_INTERVAL)
			}
		},

		stopPolling() {
			clearInterval(this.pollTimer)
			this.pollTimer = null
		},

		onVisibilityChange() {
			if (document.visibilityState === 'visible') {
				this.poll()
			}
			this.startPolling()
		},

		async poll() {
			if (this.loading || this.dragged !== null) {
				return
			}
			const id = this.id
			const revision = await this.fetchRevision()
			if (revision === null || id !== this.id) {
				return
			}
			if (this.revision === null) {
				this.revision = revision
			} else if (revision !== this.revision) {
				this.load(true)
			}
		},

		pruneSelection() {
			const visible = new Set(this.visibleCandidates.map((candidate) => candidate.id))
			if ([...this.selection].some((id) => !visible.has(id))) {
				this.selection = new Set([...this.selection].filter((id) => visible.has(id)))
			}
		},

		async toggleTerminal(stage) {
			const next = new Set(this.expanded)
			if (next.has(stage)) {
				next.delete(stage)
				this.expanded = next
				if (!this.complete) {
					this.candidates = this.candidates.filter((candidate) => candidate.stage !== stage)
				}
				this.pruneSelection()
				return
			}
			next.add(stage)
			this.expanded = next
			if (!this.complete && this.loadedInStage(stage) === 0 && this.stageTotal(stage) > 0) {
				await this.loadMore(stage)
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

		/**
		 * Each move carries the stage the user saw, so a candidate someone
		 * else moved in the meantime is left alone (HTTP 409).
		 *
		 * @param {string} stage target stage
		 */
		async bulkMove(stage) {
			const targets = this.candidates.filter((candidate) => this.selection.has(candidate.id) && candidate.stage !== stage)
			let failed = 0
			const conflicts = []
			for (const candidate of targets) {
				try {
					await api.setStage(candidate.id, stage, candidate.stage)
				} catch (error) {
					if (isStageConflict(error)) {
						conflicts.push({ error, candidate })
					} else {
						failed++
					}
				}
			}
			this.selection = new Set()
			this.load(true)
			if (conflicts.length > 3) {
				showWarning(this.n('recruiting', '%n candidate was moved by someone else in the meantime and was left unchanged', '%n candidates were moved by someone else in the meantime and were left unchanged', conflicts.length))
			} else {
				conflicts.forEach(({ error, candidate }) => handleStageConflict(error, candidate.displayName))
			}
			if (failed > 0) {
				showError(this.n('recruiting', '%n candidate could not be moved', '%n candidates could not be moved', failed))
			} else if (conflicts.length === 0) {
				showSuccess(this.t('recruiting', 'Moved to "{stage}"', { stage: this.stageLabel(stage) }))
			}
		},

		// Bulk reject: one shared decision and one mail template, rendered
		// per candidate on the server (the first candidate is previewed).
		startBulkReject() {
			const candidates = this.sortedCandidates
				.filter((candidate) => this.selection.has(candidate.id) && candidate.stage !== 'rejected')
			if (candidates.length > 0) {
				this.bulkReject = { candidates, presetReason: null, intro: '', closeAfter: false }
			}
		},

		async onBulkRejected() {
			this.selection = new Set()
			if (this.bulkReject?.closeAfter) {
				await this.setStatus('closed')
				showSuccess(this.t('recruiting', 'Opening closed'))
			}
		},

		loadedInStage(stage) {
			return countInStage(this.candidates, stage)
		},

		stageTotal(stage) {
			return this.opening?.stageCounts?.[stage] ?? this.loadedInStage(stage)
		},

		hasMore(stage) {
			// While filtering, pagination hints would be misleading: the filter
			// only searches what is loaded
			return !this.complete && this.filterQuery === '' && this.stageTotal(stage) > this.loadedInStage(stage)
		},

		async loadMore(stage) {
			this.loadingMore = true
			this.loadingStage = stage
			try {
				await this.fetchPage(stage)
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load more candidates')))
			} finally {
				this.loadingMore = false
				this.loadingStage = null
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
			if (this.dragged !== null && this.dragged.stage !== stage && this.session.isActiveStage(stage)) {
				this.dropStage = stage
				event.dataTransfer.dropEffect = 'move'
			}
		},

		async onDrop(stage) {
			const candidate = this.dragged
			this.dragged = null
			this.dropStage = null
			if (candidate && candidate.stage !== stage && this.session.isActiveStage(stage)) {
				await this.moveTo(candidate, stage)
			}
		},

		async moveTo(candidate, stage) {
			const previous = candidate.stage
			candidate.stage = stage // optimistic
			try {
				await api.setStage(candidate.id, stage, previous)
				this.patchCard(candidate.id, previous)
			} catch (error) {
				candidate.stage = previous
				if (handleStageConflict(error, candidate.displayName)) {
					this.load(true)
					return
				}
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
			if ((this.opening.activeCount ?? 0) > 0) {
				this.showCloseDialog = true
				return
			}
			await this.setStatus('closed')
			showSuccess(this.t('recruiting', 'Opening closed'))
		},

		async closeWithoutChanges() {
			this.showCloseDialog = false
			await this.setStatus('closed')
			showSuccess(this.t('recruiting', 'Opening closed'))
		},

		/**
		 * Reject every active candidate — not only the loaded pages — and
		 * close the opening once the run is over.
		 */
		async closeWithRejection() {
			this.showCloseDialog = false
			this.loadingMore = true
			try {
				for (const stage of this.session.stages) {
					while (!this.complete && this.loadedInStage(stage) < this.stageTotal(stage)) {
						if (await this.fetchPage(stage) === 0) {
							break
						}
					}
				}
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load candidates')))
				return
			} finally {
				this.loadingMore = false
			}
			const candidates = this.candidates.filter((candidate) => this.session.isActiveStage(candidate.stage))
			if (candidates.length === 0) {
				await this.closeWithoutChanges()
				return
			}
			this.bulkReject = {
				candidates,
				presetReason: 'position_filled',
				intro: this.t('recruiting', 'The opening is closed when the run is over. Candidates who cannot be rejected keep their stage.'),
				closeAfter: true,
			}
		},

		async deleteOpening() {
			const id = this.opening.id
			if (!await confirmDestructive(
				this.t('recruiting', 'Delete this opening?'),
				this.t('recruiting', '“{title}” is removed together with its hiring team and team chat. This cannot be undone.', { title: this.opening.title }),
				this.t('recruiting', 'Delete opening'),
			)) {
				return
			}
			try {
				await api.deleteOpening(id)
				this.stopPolling()
				await this.$router.push({ name: 'home' })
				this.openingsStore.remove(id)
				showSuccess(this.t('recruiting', 'Opening deleted'))
				this.openingsStore.load(true)
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not delete the opening')))
			}
		},

		onCandidateSaved() {
			this.showCandidateModal = false
			this.load(true)
		},

		onOpeningSaved(opening) {
			this.showEditModal = false
			this.openingsStore.upsert(opening)
		},

		// RejectModal reports the change through the sidebar store
		onRejected() {
			this.rejectTarget = null
		},
	},
}
</script>

<style scoped lang="scss">
@use '../css/stages.scss' as stages;

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

		&--expanded {
			border-color: var(--color-primary-element);
			background-color: var(--color-primary-element-light, var(--color-background-hover));
		}
	}
}

.board {
	@include stages.vars;
	display: grid;
	grid-template-columns: repeat(var(--board-columns, 4), minmax(220px, 1fr));
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

		&--hired {
			--recruiting-stage-accent: var(--recruiting-stage-hired);
		}

		&--rejected {
			--recruiting-stage-accent: var(--recruiting-stage-rejected);
		}

		&--withdrawn {
			--recruiting-stage-accent: var(--recruiting-stage-withdrawn);
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
		grid-template-columns: repeat(var(--board-columns, 4), minmax(240px, 280px));
	}
}
</style>
