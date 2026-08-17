<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="screening-tab">
		<!-- My vote -->
		<section v-if="candidate.permissions.vote" class="screening-tab__my-vote">
			<h3>{{ myVote ? t('recruiting', 'Your vote') : t('recruiting', 'Cast your vote') }}</h3>
			<div class="screening-tab__vote-buttons">
				<NcButton
					v-for="option in voteOptions"
					:key="option.id"
					:variant="voteValue === option.id ? 'primary' : 'secondary'"
					@click="voteValue = option.id">
					{{ option.emoji }} {{ option.label }}
				</NcButton>
			</div>
			<NcTextArea
				v-model="voteComment"
				:label="t('recruiting', 'Comment')"
				:placeholder="voteValue === 'no'
					? t('recruiting', 'Required: why are you voting no?')
					: t('recruiting', 'What stood out to you?')"
				rows="3"
				resize="vertical" />
			<NcButton
				variant="primary"
				:disabled="!voteValue || (voteValue === 'no' && !voteComment.trim()) || saving"
				@click="submitVote">
				{{ myVote ? t('recruiting', 'Update vote') : t('recruiting', 'Submit vote') }}
			</NcButton>
		</section>

		<!-- AI screening hint -->
		<section v-if="candidate.permissions.vote && session.aiAvailable">
			<div v-if="aiHint" class="screening-tab__hint">
				<strong>🪄 {{ t('recruiting', 'AI hint — verify yourself, the humans decide') }}</strong>
				<p>{{ aiHint }}</p>
			</div>
			<AiAssist
				:candidateId="candidate.id"
				action="hint"
				:label="aiHint ? t('recruiting', 'Regenerate AI screening hint') : t('recruiting', 'AI screening hint')"
				@result="aiHint = $event" />
		</section>

		<!-- All votes -->
		<section>
			<h3>{{ t('recruiting', 'Votes') }}</h3>
			<NcNoteCard v-if="votesHidden" type="info">
				{{ t('recruiting', 'Votes of others become visible once you submitted your own — this keeps everyone\'s opinion unbiased.') }}
			</NcNoteCard>
			<NcEmptyContent v-if="candidate.votes.length === 0" :name="t('recruiting', 'No votes yet')">
				<template #icon>
					<VoteOutline />
				</template>
			</NcEmptyContent>
			<ul v-else class="screening-tab__votes">
				<li v-for="vote in candidate.votes" :key="vote.uid" class="screening-tab__vote">
					<NcAvatar :user="vote.uid" :displayName="vote.displayName" :size="32" />
					<div class="screening-tab__vote-body">
						<div class="screening-tab__vote-head">
							<strong>{{ vote.displayName }}</strong>
							<span v-if="vote.vote" class="screening-tab__vote-value">
								{{ voteMeta(vote.vote).emoji }} {{ voteMeta(vote.vote).label }}
							</span>
							<span v-else class="screening-tab__vote-value screening-tab__vote-value--hidden">
								{{ t('recruiting', 'hidden') }}
							</span>
						</div>
						<p v-if="vote.comment" class="screening-tab__vote-comment">
							{{ vote.comment }}
						</p>
					</div>
				</li>
			</ul>
		</section>

		<!-- Screener assignment -->
		<section v-if="candidate.permissions.manage && candidate.openingId !== null">
			<h3>{{ t('recruiting', 'Assigned screeners') }}</h3>
			<p class="screening-tab__assign-hint">
				{{ t('recruiting', 'Assigned screeners get a notification and see the candidate under “My reviews”. Interviewers on the team only ever see the candidates assigned to them.') }}
			</p>
			<ul v-if="candidate.screeners.length > 0" class="screening-tab__screeners">
				<li v-for="screener in candidate.screeners" :key="screener.uid">
					<NcAvatar :user="screener.uid" :displayName="screener.displayName" :size="28" />
					<span class="screening-tab__screener-name">{{ screener.displayName }}</span>
					<NcButton
						variant="tertiary"
						:ariaLabel="t('recruiting', 'Remove screener')"
						@click="unassign(screener.uid)">
						<template #icon>
							<Close :size="18" />
						</template>
					</NcButton>
				</li>
			</ul>
			<div class="screening-tab__assign">
				<!-- TODO: migrate to NcSelectUsers -->
				<!-- eslint-disable @nextcloud/no-deprecated-library-props -->
				<NcSelect
					v-model="newScreeners"
					class="screening-tab__assign-select"
					:options="screenerOptions"
					label="displayName"
					:userSelect="true"
					:multiple="true"
					:placeholder="t('recruiting', 'Add screeners from the hiring team …')" />
				<!-- eslint-enable @nextcloud/no-deprecated-library-props -->
				<NcButton :disabled="newScreeners.length === 0" @click="assign">
					{{ t('recruiting', 'Assign') }}
				</NcButton>
			</div>
		</section>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import Close from 'vue-material-design-icons/Close.vue'
import VoteOutline from 'vue-material-design-icons/VoteOutline.vue'
import AiAssist from '../AiAssist.vue'
import api, { errorMessage } from '../../api.js'
import { useOpeningsStore, useSessionStore, useSidebarStore } from '../../store.js'
import { voteMeta } from '../../utils/format.js'

export default {
	name: 'ScreeningTab',
	components: {
		AiAssist,
		Close,
		NcAvatar,
		NcButton,
		NcEmptyContent,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		VoteOutline,
	},

	props: {
		candidate: { type: Object, required: true },
	},

	setup() {
		return {
			session: useSessionStore(),
			sidebar: useSidebarStore(),
			openings: useOpeningsStore(),
		}
	},

	data() {
		return {
			voteValue: null,
			voteComment: '',
			saving: false,
			newScreeners: [],
			aiHint: '',
		}
	},

	computed: {
		myVote() {
			return this.candidate.votes.find((vote) => vote.uid === this.session.uid) ?? null
		},

		votesHidden() {
			return this.candidate.votes.some((vote) => vote.vote === null)
		},

		voteOptions() {
			return ['yes', 'maybe', 'no'].map((value) => ({ id: value, ...voteMeta(value) }))
		},

		screenerOptions() {
			const opening = this.openings.byId(this.candidate.openingId)
			const assigned = new Set(this.candidate.screeners.map((screener) => screener.uid))
			return (opening?.team ?? [])
				.filter((member) => member.role !== 'observer' && !assigned.has(member.uid))
				.map((member) => ({ id: member.uid, user: member.uid, displayName: member.displayName }))
		},
	},

	watch: {
		myVote: {
			immediate: true,
			handler(vote) {
				if (vote) {
					this.voteValue = vote.vote
					this.voteComment = vote.comment ?? ''
				}
			},
		},
	},

	methods: {
		voteMeta,
		async submitVote() {
			this.saving = true
			try {
				await api.vote(this.candidate.id, this.voteValue, this.voteComment)
				showSuccess(this.t('recruiting', 'Vote saved'))
				this.sidebar.reload()
				this.sidebar.touch()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not save the vote')))
			} finally {
				this.saving = false
			}
		},

		async assign() {
			try {
				await api.assignScreeners(this.candidate.id, this.newScreeners.map((screener) => screener.id))
				this.newScreeners = []
				this.sidebar.reload()
				this.sidebar.touch()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not assign the screeners')))
			}
		},

		async unassign(uid) {
			try {
				await api.unassignScreener(this.candidate.id, uid)
				this.sidebar.reload()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not remove the screener')))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.screening-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 5);
	padding-block: calc(var(--default-grid-baseline) * 2);

	h3 {
		margin: 0 0 calc(var(--default-grid-baseline) * 2);
	}

	section {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__my-vote {
		background-color: var(--color-background-hover);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 3);
	}

	&__hint {
		border: 1px dashed var(--color-border-maxcontrast);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 3);
		font-size: 0.9em;

		p {
			margin: var(--default-grid-baseline) 0 0;
			white-space: pre-wrap;
		}
	}

	&__vote-buttons {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
	}

	&__votes {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__vote {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__vote-body {
		flex: 1;
		min-width: 0;
	}

	&__vote-head {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__vote-value {
		background-color: var(--color-background-hover);
		border-radius: var(--border-radius-pill);
		padding: 1px 8px;
		font-size: 0.85em;

		&--hidden {
			color: var(--color-text-maxcontrast);
			font-style: italic;
		}
	}

	&__vote-comment {
		margin: var(--default-grid-baseline) 0 0;
		white-space: pre-wrap;
	}

	&__screeners {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);

		li {
			display: flex;
			align-items: center;
			gap: calc(var(--default-grid-baseline) * 2);
		}
	}

	&__screener-name {
		flex: 1;
	}

	&__assign {
		display: flex;
		align-items: flex-start;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__assign-hint {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		margin: 0;
	}

	&__assign-select {
		flex: 1;
	}
}
</style>
