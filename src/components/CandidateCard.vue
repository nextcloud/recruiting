<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="candidate-card"
		:class="{ 'candidate-card--draggable': draggable }"
		:draggable="draggable"
		role="button"
		tabindex="0"
		@click="$emit('open', candidate)"
		@keydown.enter.prevent="$emit('open', candidate)"
		@dragstart="$emit('dragstart', $event)">
		<div class="candidate-card__header">
			<NcAvatar :displayName="candidate.displayName" :disableMenu="true" :size="32" />
			<div class="candidate-card__names">
				<span class="candidate-card__name">{{ candidate.displayName }}</span>
				<span v-if="candidate.email" class="candidate-card__email">{{ candidate.email }}</span>
			</div>
			<template v-if="dense">
				<span v-if="candidate.documentCount > 0" class="candidate-card__chip" :title="n('recruiting', '%n document', '%n documents', candidate.documentCount)">
					📎 {{ candidate.documentCount }}
				</span>
				<span class="candidate-card__age" :class="ageClass" :title="ageTitle">
					{{ age }}
				</span>
			</template>
			<slot name="actions" />
		</div>

		<p v-if="candidate.aiSummary" class="candidate-card__summary">
			{{ candidate.aiSummary }}
		</p>

		<div v-if="hasMeta" class="candidate-card__meta">
			<span
				v-if="attention.length > 0"
				class="candidate-card__chip candidate-card__chip--attention"
				:title="attentionTitle">
				⚠️ {{ t('recruiting', 'Stuck') }}
			</span>
			<span v-if="candidate.duplicateOf" class="candidate-card__chip candidate-card__chip--warning" :title="t('recruiting', 'This person applied before')">
				{{ t('recruiting', 'Duplicate') }}
			</span>
			<span v-if="candidate.nextInterviewAt" class="candidate-card__chip candidate-card__chip--interview">
				📅 {{ nextInterview }}
			</span>
			<span v-if="!dense && candidate.documentCount > 0" class="candidate-card__chip" :title="n('recruiting', '%n document', '%n documents', candidate.documentCount)">
				📎 {{ candidate.documentCount }}
			</span>
			<span
				v-if="candidate.voteCount > 0"
				class="candidate-card__chip"
				:title="voteTitle">
				<template v-if="candidate.voteTally">
					<span v-if="candidate.voteTally.yes" class="candidate-card__vote">👍{{ candidate.voteTally.yes }}</span>
					<span v-if="candidate.voteTally.maybe" class="candidate-card__vote">🤔{{ candidate.voteTally.maybe }}</span>
					<span v-if="candidate.voteTally.no" class="candidate-card__vote">👎{{ candidate.voteTally.no }}</span>
				</template>
				<template v-else>
					🗳️ {{ candidate.voteCount }}
				</template>
			</span>
			<span v-if="!dense && screeners.length > 0" class="candidate-card__screeners">
				<span
					v-for="screener in screeners.slice(0, 4)"
					:key="screener.uid"
					class="candidate-card__screener"
					:class="{ 'candidate-card__screener--voted': screener.voted }"
					:title="screener.voted
						? t('recruiting', '{name} has voted', { name: screener.displayName })
						: t('recruiting', '{name} has not voted yet', { name: screener.displayName })">
					<NcAvatar
						:user="screener.uid"
						:displayName="screener.displayName"
						:disableMenu="true"
						:hideStatus="true"
						:size="24" />
				</span>
				<span v-if="screeners.length > 4" class="candidate-card__screener-more">+{{ screeners.length - 4 }}</span>
			</span>
			<span v-if="!dense" class="candidate-card__age" :class="ageClass" :title="ageTitle">
				{{ age }}
			</span>
		</div>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { daysSince, fromNow } from '../utils/format.js'

export default {
	name: 'CandidateCard',
	components: { NcAvatar },
	props: {
		candidate: { type: Object, required: true },
		draggable: { type: Boolean, default: false },
		// List rows are wide enough to carry the documents chip and the age in
		// the header line, which saves the card a row. Board columns are not.
		dense: { type: Boolean, default: false },
	},
	emits: ['open', 'dragstart'],
	computed: {
		hasMeta() {
			return !this.dense
				|| Boolean(this.candidate.duplicateOf)
				|| Boolean(this.candidate.nextInterviewAt)
				|| this.candidate.voteCount > 0
				|| this.attention.length > 0
		},
		attention() {
			return this.candidate.attention ?? []
		},
		attentionTitle() {
			const labels = {
				waiting: this.t('recruiting', 'Waiting in "New" for a week'),
				no_votes: this.t('recruiting', 'In screening without a single vote'),
				no_interview: this.t('recruiting', 'No upcoming interview booked'),
				no_offer: this.t('recruiting', 'In "Offer" without an active offer'),
				offer_expiring: this.t('recruiting', 'The offer expires within three days'),
				stale: this.t('recruiting', 'More than 30 days in this stage'),
			}
			return this.attention.map((reason) => labels[reason] ?? reason).join('\n')
		},
		screeners() {
			return this.candidate.screeners ?? []
		},
		daysInStage() {
			return daysSince(this.candidate.stageChangedAt || this.candidate.createdAt)
		},
		ageClass() {
			// Terminal candidates are done — their age is history, not urgency
			if (['hired', 'rejected', 'withdrawn'].includes(this.candidate.stage)) {
				return ''
			}
			if (this.daysInStage >= 30) {
				return 'candidate-card__age--overdue'
			}
			if (this.daysInStage >= 14) {
				return 'candidate-card__age--aging'
			}
			return ''
		},
		ageTitle() {
			return this.candidate.openingId === null
				? this.t('recruiting', 'Waiting in the triage inbox')
				: this.t('recruiting', 'Time in this stage')
		},
		age() {
			const days = daysSince(this.candidate.stageChangedAt || this.candidate.createdAt)
			if (days === 0) {
				return this.t('recruiting', 'today')
			}
			return this.n('recruiting', '%n day', '%n days', days)
		},
		nextInterview() {
			return fromNow(this.candidate.nextInterviewAt)
		},
		voteTitle() {
			if (this.candidate.voteTally) {
				return this.t('recruiting', 'Screening votes: {yes}× yes, {maybe}× maybe, {no}× no', {
					yes: this.candidate.voteTally.yes ?? 0,
					maybe: this.candidate.voteTally.maybe ?? 0,
					no: this.candidate.voteTally.no ?? 0,
				})
			}
			return this.t('recruiting', 'Votes are hidden until you cast your own')
		},
	},
}
</script>

<style scoped lang="scss">
.candidate-card {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	cursor: pointer;
	transition: box-shadow 0.15s ease, border-color 0.15s ease;

	&:hover,
	&:focus-visible {
		border-color: var(--color-primary-element);
		box-shadow: 0 2px 8px var(--color-box-shadow);
	}

	&--draggable {
		cursor: grab;

		&:active {
			cursor: grabbing;
		}
	}

	&__header {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		min-width: 0;
	}

	&__names {
		display: flex;
		flex-direction: column;
		min-width: 0;
		flex: 1;
	}

	&__name {
		font-weight: 600;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__email {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__summary {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}

	&__meta {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: var(--default-grid-baseline);
	}

	// chip and age size themselves, so they look the same in the meta row and
	// in the header line of a dense card
	&__chip {
		background-color: var(--color-background-hover);
		border-radius: var(--border-radius-pill);
		padding: 2px 8px;
		white-space: nowrap;
		font-size: 0.85em;

		&--warning {
			background-color: var(--color-warning-hover, var(--color-background-hover));
		}

		&--interview {
			background-color: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}

		&--attention {
			background-color: var(--color-warning-hover, var(--color-background-hover));
			cursor: help;
		}
	}

	&__screeners {
		display: flex;
		align-items: center;
	}

	&__screener {
		display: inline-flex;
		border-radius: 50%;
		border: 2px solid var(--color-main-background);
		margin-inline-start: -6px;

		&:first-child {
			margin-inline-start: 0;
		}

		// the ring: this screener has cast their vote (only the fact,
		// never the value — spec §4.3)
		&--voted {
			border-color: var(--color-success-text, var(--color-success, #2d7b41));
		}
	}

	&__screener-more {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
		margin-inline-start: 2px;
	}

	&__vote {
		margin-inline-end: 4px;

		&:last-child {
			margin-inline-end: 0;
		}
	}

	&__age {
		margin-inline-start: auto;
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
		white-space: nowrap;

		// aging signal: amber past two weeks in a stage, red past a month
		&--aging {
			color: var(--color-warning-text, #99570c);
			font-weight: 600;
		}

		&--overdue {
			color: var(--color-error-text, #b3251e);
			font-weight: 600;
		}
	}
}
</style>
