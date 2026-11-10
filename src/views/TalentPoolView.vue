<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pool-view">
		<h2>{{ t('recruiting', 'Talent pool') }}</h2>
		<p class="pool-view__hint">
			{{ t('recruiting', 'Candidates who explicitly agreed to be considered for future openings — nobody lands here without confirming the consent link from their rejection mail. “Add to opening” creates a fresh copy (documents included) in that opening’s “New” column; the pool entry stays. Membership expires automatically after the period set in the admin settings.') }}
		</p>

		<NcTextField
			v-model="query"
			class="pool-view__search"
			type="search"
			:label="t('recruiting', 'Search name, email or CV text')"
			:placeholder="t('recruiting', 'e.g. Kubernetes')"
			:showTrailingButton="query !== ''"
			@trailingButtonClick="query = ''">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcTextField>

		<NcLoadingIcon v-if="loading" :size="44" class="pool-view__loading" />
		<NcEmptyContent
			v-else-if="members.length === 0 && query.trim() !== ''"
			:name="t('recruiting', 'No matches')"
			:description="t('recruiting', 'Nobody in the pool matches “{query}”. The search covers names, email addresses and the text of uploaded documents.', { query: query.trim() })">
			<template #icon>
				<Magnify />
			</template>
		</NcEmptyContent>
		<NcEmptyContent
			v-else-if="members.length === 0"
			:name="t('recruiting', 'The pool is empty')"
			:description="t('recruiting', 'When rejecting a candidate, tick “Ask to stay in our talent pool” — they appear here once they consent.')">
			<template #icon>
				<AccountHeartOutline />
			</template>
		</NcEmptyContent>

		<div v-else class="pool-view__list">
			<div v-for="member in members" :key="member.id" class="pool-member">
				<NcAvatar :displayName="member.displayName" :disableMenu="true" :size="36" />
				<div
					class="pool-member__info"
					role="button"
					tabindex="0"
					@click="sidebar.open(member.id)"
					@keydown.enter.prevent="sidebar.open(member.id)">
					<strong>{{ member.displayName }}</strong>
					<span class="pool-member__meta">
						{{ member.email }}
						<template v-if="member.openingTitle">
							· {{ t('recruiting', 'applied for {title}', { title: member.openingTitle }) }}
						</template>
						· {{ t('recruiting', 'consented {when}', { when: fromNow(member.poolConsentAt) }) }}
					</span>
				</div>
				<div class="pool-member__actions" @click.stop>
					<NcSelect
						:modelValue="null"
						:options="openingOptions"
						label="label"
						:placeholder="t('recruiting', 'Add to opening …')"
						@update:modelValue="addTo(member, $event)" />
				</div>
			</div>
			<NcButton v-if="hasMore" variant="tertiary" @click="loadMore">
				{{ t('recruiting', 'Show more') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountHeartOutline from 'vue-material-design-icons/AccountHeartOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import api, { errorMessage } from '../api.js'
import { useOpeningsStore, useSidebarStore } from '../store.js'
import { fromNow } from '../utils/format.js'

export default {
	name: 'TalentPoolView',
	components: { AccountHeartOutline, Magnify, NcAvatar, NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField },
	setup() {
		return {
			openings: useOpeningsStore(),
			sidebar: useSidebarStore(),
		}
	},

	data() {
		return {
			members: [],
			hasMore: false,
			query: '',
			loading: true,
			searchTimer: null,
			/** guards against out-of-order search responses */
			requestToken: 0,
		}
	},

	computed: {
		openingOptions() {
			return this.openings.open
				.filter((opening) => opening.status === 'open')
				.map((opening) => ({ id: opening.id, label: opening.title }))
		},
	},

	watch: {
		'sidebar.version': function() {
			this.load(true)
		},

		query() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.load(), 300)
		},
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	created() {
		this.load()
	},

	methods: {
		fromNow,
		async load(silent = false) {
			if (!silent) {
				this.loading = true
			}
			const token = ++this.requestToken
			try {
				const { data } = await api.getPool({ q: this.query.trim() })
				if (token === this.requestToken) {
					this.members = data.members
					this.hasMore = data.hasMore
				}
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load the talent pool')))
			} finally {
				if (token === this.requestToken) {
					this.loading = false
				}
			}
		},

		async loadMore() {
			const token = ++this.requestToken
			try {
				const { data } = await api.getPool({ q: this.query.trim(), offset: this.members.length })
				if (token === this.requestToken) {
					this.members = [...this.members, ...data.members]
					this.hasMore = data.hasMore
				}
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load the talent pool')))
			}
		},

		async addTo(member, option) {
			if (!option) {
				return
			}
			try {
				const { data } = await api.addPoolToOpening(member.id, option.id)
				showSuccess(this.t('recruiting', '{name} added to "{title}"', { name: member.displayName, title: option.label }))
				this.sidebar.open(data.id)
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not add the candidate')))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.pool-view {
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

	&__search {
		max-width: 400px;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}

.pool-member {
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
		cursor: pointer;
	}

	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__actions {
		min-width: 220px;
	}
}
</style>
