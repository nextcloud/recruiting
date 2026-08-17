<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="activity-tab">
		<NcLoadingIcon v-if="loading" :size="24" />

		<ul v-else class="activity-tab__stream">
			<li v-for="entry in stream" :key="entry.key" class="entry" :class="{ 'entry--comment': entry.kind === 'comment' }">
				<template v-if="entry.kind === 'comment'">
					<NcAvatar :user="entry.authorUid" :displayName="entry.authorDisplayName" :size="32" />
					<div class="entry__body">
						<div class="entry__head">
							<strong>{{ entry.authorDisplayName }}</strong>
							<span class="entry__time">{{ fromNow(entry.createdAt) }}</span>
						</div>
						<p class="entry__message">{{ entry.message }}</p>
					</div>
				</template>
				<template v-else>
					<span class="entry__dot" aria-hidden="true">{{ entry.icon }}</span>
					<div class="entry__body">
						<div class="entry__head">
							<span>{{ entry.text }}</span>
							<span class="entry__time">{{ fromNow(entry.createdAt) }}</span>
						</div>
						<details v-if="entry.details" class="entry__details">
							<summary>{{ entry.detailsLabel }}</summary>
							<pre>{{ entry.details }}</pre>
						</details>
					</div>
				</template>
			</li>
		</ul>

		<div v-if="candidate.permissions.comment" class="activity-tab__composer">
			<NcTextArea
				v-model="newComment"
				:label="t('recruiting', 'Add a comment')"
				:placeholder="t('recruiting', 'Discuss with the hiring team — @mention to notify someone')"
				rows="3"
				resize="vertical"
				@keydown.enter.ctrl.exact="submitComment" />
			<NcButton variant="primary" :disabled="!newComment.trim() || sending" @click="submitComment">
				<template #icon>
					<SendOutline :size="20" />
				</template>
				{{ t('recruiting', 'Comment') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import SendOutline from 'vue-material-design-icons/SendOutline.vue'
import api, { errorMessage } from '../../api.js'
import { formatDateTime, fromNow, offerStatusLabel, reasonLabel, stageLabel } from '../../utils/format.js'

export default {
	name: 'ActivityTab',
	components: { NcAvatar, NcButton, NcLoadingIcon, NcTextArea, SendOutline },
	props: {
		candidate: { type: Object, required: true },
	},
	data() {
		return {
			comments: [],
			loading: true,
			newComment: '',
			sending: false,
		}
	},
	computed: {
		stream() {
			const events = this.candidate.timeline.map((event, index) => ({
				kind: 'event',
				key: 'event-' + (event.id ?? index),
				createdAt: event.createdAt,
				...this.renderEvent(event),
			}))
			const comments = this.comments.map((comment) => ({
				kind: 'comment',
				key: 'comment-' + comment.id,
				...comment,
			}))
			return [...events, ...comments]
				.sort((a, b) => String(a.createdAt).localeCompare(String(b.createdAt)))
		},
	},
	watch: {
		'candidate.id': {
			immediate: true,
			handler() {
				this.loadComments()
			},
		},
	},
	methods: {
		fromNow,
		renderEvent(event) {
			const actor = event.actorDisplayName ?? this.t('recruiting', 'System')
			const data = event.data ?? {}
			switch (event.type) {
				case 'created':
					return { icon: '✨', text: this.t('recruiting', 'Application created') }
				case 'mail_received':
					if (data.reply) {
						return {
							icon: '📥',
							text: this.t('recruiting', 'The candidate replied: {subject}', { subject: data.subject ?? '' }),
							details: data.body,
							detailsLabel: this.t('recruiting', 'Show message'),
						}
					}
					return {
						icon: '📥',
						text: this.t('recruiting', 'Application received by email: {subject}', { subject: data.subject ?? '' }),
						details: data.body,
						detailsLabel: this.t('recruiting', 'Show original message'),
					}
				case 'mail_sent':
					// Content is management information — the backend redacts it
					// for roles that may not see offer data (spec §4.6)
					if (data.redacted) {
						return { icon: '📤', text: this.t('recruiting', '{actor} sent an email to the candidate', { actor }) }
					}
					return {
						icon: '📤',
						text: this.t('recruiting', '{actor} sent "{subject}"', { actor, subject: data.subject ?? '' }),
						details: data.body,
						detailsLabel: this.t('recruiting', 'Show message'),
					}
				case 'offer_created':
					return { icon: '🤝', text: this.t('recruiting', '{actor} drafted an offer', { actor }) }
				case 'offer_submitted':
					return { icon: '📝', text: this.t('recruiting', '{actor} requested approval from {approver}', { actor, approver: data.approver ?? '' }) }
				case 'offer_approved':
					return { icon: '✅', text: this.t('recruiting', '{actor} approved the offer', { actor }) }
				case 'offer_approval_declined':
					return { icon: '↩️', text: this.t('recruiting', '{actor} sent the offer back to draft', { actor }) }
				case 'offer_sent':
					return { icon: '📨', text: this.t('recruiting', '{actor} sent the offer to the candidate', { actor }) }
				case 'offer_response':
					return { icon: '💬', text: this.t('recruiting', 'The candidate responded to the offer: {response}', { response: offerStatusLabel(data.response ?? '') }) }
				case 'offer_expired':
					return { icon: '⌛', text: this.t('recruiting', 'The offer expired without a response') }
				case 'offer_withdrawn':
					return { icon: '🚫', text: this.t('recruiting', '{actor} withdrew the offer', { actor }) }
				case 'pool_invited':
					return { icon: '💌', text: this.t('recruiting', '{actor} asked the candidate to join the talent pool', { actor }) }
				case 'pool_joined':
					return { icon: '🤗', text: this.t('recruiting', 'The candidate consented to staying in the talent pool') }
				case 'pool_copied':
					return { icon: '📋', text: this.t('recruiting', '{actor} added the candidate to "{title}"', { actor, title: data.openingTitle ?? '' }) }
				case 'anonymized':
					return { icon: '🕶️', text: this.t('recruiting', 'Personal data was anonymized (retention period ended)') }
				case 'stage_changed':
					return {
						icon: '🔀',
						text: data.reason
							? this.t('recruiting', '{actor} rejected the candidate ({reason})', { actor, reason: reasonLabel(data.reason) })
							: this.t('recruiting', '{actor} moved the candidate from "{from}" to "{to}"', {
								actor,
								from: stageLabel(data.from),
								to: stageLabel(data.to),
							}),
					}
				case 'opening_assigned':
					return { icon: '📌', text: this.t('recruiting', '{actor} assigned the candidate to "{title}"', { actor, title: data.openingTitle ?? '' }) }
				case 'screener_assigned':
					return { icon: '👀', text: this.t('recruiting', '{actor} asked {screener} to review', { actor, screener: data.screener ?? '' }) }
				case 'screener_removed':
					return { icon: '🙈', text: this.t('recruiting', '{actor} removed {screener} as screener', { actor, screener: data.screener ?? '' }) }
				case 'vote_cast':
					return { icon: '🗳️', text: this.t('recruiting', '{actor} cast a vote', { actor }) }
				case 'document_added':
					return { icon: '📎', text: this.t('recruiting', '{actor} added "{name}"', { actor, name: data.name ?? '' }) }
				case 'interview_proposed':
					return { icon: '📅', text: this.t('recruiting', '{actor} proposed the interview "{title}"', { actor, title: data.title ?? '' }) }
				case 'interview_confirmed':
					return {
						icon: '✅',
						text: this.t('recruiting', 'The candidate confirmed "{title}" for {date}', {
							title: data.title ?? '',
							date: formatDateTime(data.startAt),
						}),
					}
				case 'interview_cancelled':
					return { icon: '🚫', text: this.t('recruiting', 'The interview "{title}" was cancelled', { title: data.title ?? '' }) }
				default:
					return { icon: '•', text: event.type }
			}
		},
		async loadComments() {
			this.loading = true
			try {
				const { data } = await api.getComments(this.candidate.id)
				this.comments = data
			} catch {
				this.comments = []
			} finally {
				this.loading = false
			}
		},
		async submitComment() {
			if (!this.newComment.trim()) {
				return
			}
			this.sending = true
			try {
				await api.addComment(this.candidate.id, this.newComment)
				this.newComment = ''
				await this.loadComments()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not post the comment')))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.activity-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	padding-block: calc(var(--default-grid-baseline) * 2);

	&__stream {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__composer {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		align-items: flex-end;
		border-top: 1px solid var(--color-border);
		padding-top: calc(var(--default-grid-baseline) * 3);

		> :first-child {
			width: 100%;
		}
	}
}

.entry {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);

	&--comment {
		background-color: var(--color-background-hover);
		border-radius: var(--border-radius-large);
		padding: calc(var(--default-grid-baseline) * 2);
	}

	&__dot {
		width: 32px;
		text-align: center;
		flex-shrink: 0;
	}

	&__body {
		flex: 1;
		min-width: 0;
	}

	&__head {
		display: flex;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__time {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
		white-space: nowrap;
	}

	&__message {
		margin: var(--default-grid-baseline) 0 0;
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}

	&__details {
		margin-top: var(--default-grid-baseline);

		summary {
			cursor: pointer;
			color: var(--color-text-maxcontrast);
			font-size: 0.85em;
		}

		pre {
			white-space: pre-wrap;
			overflow-wrap: anywhere;
			background-color: var(--color-background-hover);
			border-radius: var(--border-radius-large);
			padding: calc(var(--default-grid-baseline) * 2);
			margin-top: var(--default-grid-baseline);
			font-family: inherit;
			font-size: 0.9em;
		}
	}
}
</style>
