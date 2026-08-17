<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="my-reviews">
		<h2>{{ t('recruiting', 'My reviews') }}</h2>
		<p class="my-reviews__hint">
			{{ t('recruiting', 'Candidates you were asked to screen. Click a card, read the documents, and vote 👍 / 🤔 / 👎 with a comment — the votes of others stay hidden until you have cast your own, so every opinion is unbiased. You can update your vote at any time.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="44" class="my-reviews__loading" />
		<NcEmptyContent
			v-else-if="cards.length === 0"
			:name="t('recruiting', 'Nothing to review')"
			:description="t('recruiting', 'You are all caught up.')">
			<template #icon>
				<VoteOutline />
			</template>
		</NcEmptyContent>

		<div v-else class="my-reviews__list">
			<div v-for="card in cards" :key="card.id" class="my-reviews__item">
				<CandidateCard :candidate="card" @open="sidebar.open(card.id)">
					<template #actions>
						<span
							class="my-reviews__badge"
							:class="{ 'my-reviews__badge--done': card.myVote }">
							{{ card.myVote ? t('recruiting', 'Voted') : t('recruiting', 'Vote needed') }}
						</span>
					</template>
				</CandidateCard>
			</div>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import VoteOutline from 'vue-material-design-icons/VoteOutline.vue'
import CandidateCard from '../components/CandidateCard.vue'
import api, { errorMessage } from '../api.js'
import { useSidebarStore } from '../store.js'

export default {
	name: 'MyReviewsView',
	components: { CandidateCard, NcEmptyContent, NcLoadingIcon, VoteOutline },
	setup() {
		return { sidebar: useSidebarStore() }
	},

	data() {
		return {
			cards: [],
			loading: true,
		}
	},

	watch: {
		'sidebar.version': function() {
			this.load(true)
		},
	},

	created() {
		this.load()
	},

	methods: {
		async load(silent = false) {
			if (!silent) {
				this.loading = true
			}
			try {
				const { data } = await api.getMyReviews()
				this.cards = data
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load your reviews')))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.my-reviews {
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

	&__loading {
		margin-top: 15vh;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__badge {
		border-radius: var(--border-radius-pill);
		padding: 2px 10px;
		font-size: 0.85em;
		background-color: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		white-space: nowrap;

		&--done {
			background-color: var(--color-success-hover, var(--color-background-hover));
			color: var(--color-main-text);
		}
	}
}
</style>
