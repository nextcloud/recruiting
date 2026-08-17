<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="offer-tab">
		<NcEmptyContent
			v-if="offers.length === 0 && !creating"
			:name="t('recruiting', 'No offer yet')"
			:description="canManage ? t('recruiting', 'Draft the terms, get them approved, send the offer — and track the response.') : ''">
			<template #icon>
				<HandshakeOutline />
			</template>
			<template v-if="canManage && !isTerminal" #action>
				<NcButton variant="primary" @click="startCreate">
					{{ t('recruiting', 'Draft an offer') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<!-- Draft form (create or edit) -->
		<form v-if="creating || editing" class="offer-form" @submit.prevent="saveDraft">
			<h3>{{ editing ? t('recruiting', 'Edit offer') : t('recruiting', 'New offer') }}</h3>
			<p class="offer-form__hint">
				{{ t('recruiting', 'Offer terms are internal: only recruiters, this opening’s hiring managers and the approver can see them — never interviewers or observers. The candidate learns about the offer only when you send the offer email.') }}
			</p>
			<NcTextField v-model="form.jobTitle" :label="t('recruiting', 'Job title')" required />
			<div class="offer-form__row">
				<NcTextField v-model="form.salaryAmount" inputmode="decimal" :label="t('recruiting', 'Salary')" placeholder="72000" />
				<NcTextField v-model="form.salaryCurrency" :label="t('recruiting', 'Currency')" placeholder="EUR" maxlength="3" />
				<NcSelect
					v-model="form.salaryPeriod"
					class="offer-form__period"
					:options="periodOptions"
					:inputLabel="t('recruiting', 'Period')"
					label="label"
					:clearable="false" />
			</div>
			<div class="offer-form__row">
				<NcDateTimePickerNative v-model="form.startDate" type="date" :label="t('recruiting', 'Start date')" />
				<NcDateTimePickerNative v-model="form.validUntil" type="date" :label="t('recruiting', 'Offer valid until')" />
			</div>
			<NcTextArea v-model="form.notes" :label="t('recruiting', 'Internal notes')" rows="3" resize="vertical" />
			<div class="offer-tab__buttons">
				<NcButton @click="creating = false; editing = null">
					{{ t('recruiting', 'Cancel') }}
				</NcButton>
				<NcButton type="submit" variant="primary" :disabled="working || !form.jobTitle.trim()">
					{{ t('recruiting', 'Save draft') }}
				</NcButton>
			</div>
		</form>

		<!-- Offers, newest first -->
		<div v-for="(offer, index) in offers" :key="offer.id" class="offer" :class="{ 'offer--past': isPast(offer) }">
			<div class="offer__head">
				<strong>{{ offer.jobTitle }}</strong>
				<span class="offer__status" :class="'offer__status--' + offer.status">
					{{ offerStatusLabel(offer.status) }}
				</span>
			</div>

			<dl class="offer__facts">
				<div v-if="offer.salaryAmount">
					<dt>{{ t('recruiting', 'Salary') }}</dt>
					<dd>{{ offer.salaryAmount }} {{ offer.salaryCurrency }} {{ salaryPeriodLabel(offer.salaryPeriod) }}</dd>
				</div>
				<div v-if="offer.startDate">
					<dt>{{ t('recruiting', 'Start date') }}</dt>
					<dd>{{ formatDate(offer.startDate) }}</dd>
				</div>
				<div v-if="offer.validUntil">
					<dt>{{ t('recruiting', 'Valid until') }}</dt>
					<dd>{{ formatDate(offer.validUntil) }}</dd>
				</div>
				<div v-if="offer.approverUid">
					<dt>{{ t('recruiting', 'Approver') }}</dt>
					<dd>{{ offer.approverDisplayName }}</dd>
				</div>
				<div v-if="offer.notes">
					<dt>{{ t('recruiting', 'Notes') }}</dt>
					<dd>{{ offer.notes }}</dd>
				</div>
			</dl>

			<!-- Workflow actions on the newest offer only -->
			<template v-if="index === 0">
				<!-- draft -->
				<div v-if="offer.status === 'draft' && canManage" class="offer__workflow">
					<div class="offer__submit">
						<UserPicker v-model="approver" :placeholder="t('recruiting', 'Who approves this offer?')" />
						<NcButton variant="primary" :disabled="!approver || working" @click="submit(offer)">
							{{ t('recruiting', 'Request approval') }}
						</NcButton>
					</div>
					<p class="offer__hint">
						{{ t('recruiting', 'An offer needs a second pair of eyes before it can go out — you cannot approve your own.') }}
					</p>
					<NcButton variant="tertiary" @click="startEdit(offer)">
						{{ t('recruiting', 'Edit terms') }}
					</NcButton>
				</div>

				<!-- pending approval -->
				<div v-else-if="offer.status === 'pending_approval'" class="offer__workflow">
					<template v-if="offer.approverUid === session.uid">
						<p class="offer__hint">{{ t('recruiting', 'This offer is waiting for your decision.') }}</p>
						<div class="offer-tab__buttons offer-tab__buttons--start">
							<NcButton variant="primary" :disabled="working" @click="approve(offer)">
								<template #icon>
									<CheckCircleOutline :size="20" />
								</template>
								{{ t('recruiting', 'Approve offer') }}
							</NcButton>
							<NcButton :disabled="working" @click="declineApproval(offer)">
								{{ t('recruiting', 'Send back to draft') }}
							</NcButton>
						</div>
					</template>
					<p v-else class="offer__hint">
						{{ t('recruiting', 'Waiting for {name} to approve.', { name: offer.approverDisplayName }) }}
					</p>
				</div>

				<!-- approved -->
				<div v-else-if="offer.status === 'approved' && canManage" class="offer__workflow">
					<template v-if="composing">
						<NcTextField v-model="mailSubject" :label="t('recruiting', 'Subject')" />
						<NcTextArea v-model="mailBody" :label="t('recruiting', 'Message')" rows="10" resize="vertical" />
						<AiAssist
							:candidateId="candidate.id"
							action="draft_offer"
							:label="t('recruiting', 'Draft with Assistant')"
							@result="mailBody = $event" />
						<div class="offer-tab__buttons">
							<NcButton @click="composing = false">
								{{ t('recruiting', 'Cancel') }}
							</NcButton>
							<NcButton variant="primary" :disabled="working" @click="sendWithMail(offer)">
								<template #icon>
									<SendOutline :size="20" />
								</template>
								{{ t('recruiting', 'Send offer email') }}
							</NcButton>
						</div>
					</template>
					<div v-else class="offer-tab__buttons offer-tab__buttons--start">
						<NcButton
							variant="primary"
							:disabled="working || !candidate.email"
							:title="t('recruiting', 'Opens the mail preview — the offer only counts as sent after you confirm. The card moves to the “Offer” column.')"
							@click="composeSend(offer)">
							<template #icon>
								<SendOutline :size="20" />
							</template>
							{{ t('recruiting', 'Send offer email …') }}
						</NcButton>
						<NcButton
							:disabled="working"
							:title="t('recruiting', 'Use this when the offer went out another way (phone, letter). Starts the validity clock and response tracking.')"
							@click="sendWithoutMail(offer)">
							{{ t('recruiting', 'Mark as sent (no email)') }}
						</NcButton>
					</div>
				</div>

				<!-- sent / negotiating -->
				<div v-else-if="['sent', 'negotiating'].includes(offer.status) && canManage" class="offer__workflow">
					<p class="offer__hint">
						{{ t('recruiting', 'What did the candidate say? “Accepted” moves them to “Hired” 🎉. “Declined” keeps them in the pipeline so you can decide what happens next. Unanswered offers expire automatically after the validity date — you get a warning three days before.') }}
					</p>
					<div class="offer-tab__buttons offer-tab__buttons--start">
						<NcButton variant="primary" :disabled="working" @click="respond(offer, 'accepted')">
							🎉 {{ t('recruiting', 'Accepted') }}
						</NcButton>
						<NcButton v-if="offer.status === 'sent'" :disabled="working" @click="respond(offer, 'negotiating')">
							{{ t('recruiting', 'Negotiating') }}
						</NcButton>
						<NcButton :disabled="working" @click="respond(offer, 'declined')">
							{{ t('recruiting', 'Declined') }}
						</NcButton>
					</div>
				</div>

				<NcButton
					v-if="canManage && !['accepted', 'declined', 'expired', 'withdrawn'].includes(offer.status)"
					variant="tertiary"
					:disabled="working"
					@click="withdraw(offer)">
					{{ t('recruiting', 'Withdraw offer') }}
				</NcButton>
			</template>
		</div>

		<NcButton
			v-if="offers.length > 0 && allPast && canManage && !isTerminal && !creating"
			@click="startCreate">
			{{ t('recruiting', 'Draft a new offer') }}
		</NcButton>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import SendOutline from 'vue-material-design-icons/SendOutline.vue'
import api, { errorMessage } from '../../api.js'
import { confirmDestructive } from '../../utils/confirm.js'
import { useSessionStore, useSidebarStore } from '../../store.js'
import { formatDate, offerStatusLabel, salaryPeriodLabel } from '../../utils/format.js'
import AiAssist from '../AiAssist.vue'
import UserPicker from '../UserPicker.vue'

const toDateInput = (value) => (value ? new Date(value + 'T00:00:00') : null)
const fromDateInput = (value) => {
	if (!value) {
		return ''
	}
	const date = value instanceof Date ? value : new Date(value)
	return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

export default {
	name: 'OfferTab',
	components: {
		AiAssist,
		CheckCircleOutline,
		HandshakeOutline,
		NcButton,
		NcDateTimePickerNative,
		NcEmptyContent,
		NcSelect,
		NcTextArea,
		NcTextField,
		SendOutline,
		UserPicker,
	},
	props: {
		candidate: { type: Object, required: true },
	},
	setup() {
		return {
			session: useSessionStore(),
			sidebar: useSidebarStore(),
		}
	},
	data() {
		return {
			creating: false,
			editing: null,
			working: false,
			approver: null,
			composing: false,
			mailSubject: '',
			mailBody: '',
			form: this.emptyForm(),
		}
	},
	computed: {
		offers() {
			return this.candidate.offers ?? []
		},
		canManage() {
			return this.candidate.permissions.manage
		},
		isTerminal() {
			return this.session.isTerminalStage(this.candidate.stage)
		},
		allPast() {
			return this.offers.every((offer) => this.isPast(offer))
		},
		periodOptions() {
			return ['year', 'month', 'hour'].map((period) => ({ id: period, label: salaryPeriodLabel(period) }))
		},
	},
	methods: {
		formatDate,
		offerStatusLabel,
		salaryPeriodLabel,
		isPast(offer) {
			return ['declined', 'expired', 'withdrawn'].includes(offer.status)
		},
		emptyForm() {
			return {
				jobTitle: '',
				salaryAmount: '',
				salaryCurrency: 'EUR',
				salaryPeriod: { id: 'year', label: salaryPeriodLabel('year') },
				startDate: null,
				validUntil: null,
				notes: '',
			}
		},
		startCreate() {
			this.form = this.emptyForm()
			this.form.jobTitle = this.candidate.openingTitle ?? ''
			this.creating = true
			this.editing = null
		},
		startEdit(offer) {
			this.form = {
				jobTitle: offer.jobTitle,
				salaryAmount: offer.salaryAmount,
				salaryCurrency: offer.salaryCurrency,
				salaryPeriod: { id: offer.salaryPeriod, label: salaryPeriodLabel(offer.salaryPeriod) },
				startDate: toDateInput(offer.startDate),
				validUntil: toDateInput(offer.validUntil),
				notes: offer.notes,
			}
			this.editing = offer
			this.creating = false
		},
		payload() {
			return {
				jobTitle: this.form.jobTitle,
				salaryAmount: this.form.salaryAmount,
				salaryCurrency: this.form.salaryCurrency,
				salaryPeriod: this.form.salaryPeriod?.id ?? 'year',
				startDate: fromDateInput(this.form.startDate),
				validUntil: fromDateInput(this.form.validUntil),
				notes: this.form.notes,
			}
		},
		async run(action, successMessage = '') {
			this.working = true
			try {
				await action()
				if (successMessage) {
					showSuccess(successMessage)
				}
				this.sidebar.reload()
				this.sidebar.touch()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'The offer action failed')))
			} finally {
				this.working = false
			}
		},
		saveDraft() {
			const editing = this.editing
			this.run(async () => {
				if (editing) {
					await api.updateOffer(editing.id, this.payload())
				} else {
					await api.createOffer(this.candidate.id, this.payload())
				}
				this.creating = false
				this.editing = null
			})
		},
		submit(offer) {
			this.run(() => api.submitOffer(offer.id, this.approver.id), this.t('recruiting', 'Approval requested'))
		},
		approve(offer) {
			this.run(() => api.approveOffer(offer.id), this.t('recruiting', 'Offer approved'))
		},
		declineApproval(offer) {
			const note = prompt(this.t('recruiting', 'A short note for the offer creator (optional):')) ?? ''
			this.run(() => api.declineOfferApproval(offer.id, note))
		},
		async composeSend(offer) {
			this.working = true
			try {
				const { data } = await api.previewMail(this.candidate.id, 'offer')
				this.mailSubject = data.subject
				this.mailBody = data.body
				this.composing = true
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load the mail template')))
			} finally {
				this.working = false
			}
		},
		sendWithMail(offer) {
			this.run(async () => {
				await api.sendOffer(offer.id, { mailSubject: this.mailSubject, mailBody: this.mailBody })
				this.composing = false
			}, this.t('recruiting', 'Offer sent'))
		},
		sendWithoutMail(offer) {
			this.run(() => api.sendOffer(offer.id), this.t('recruiting', 'Offer marked as sent'))
		},
		async respond(offer, response) {
			if (response === 'accepted'
				&& !await confirmDestructive(
					this.t('recruiting', 'Mark the offer as accepted?'),
					this.t('recruiting', '{name} moves to "Hired" — remember to close the opening and notify the other candidates.', { name: this.candidate.displayName }),
					this.t('recruiting', 'Mark as accepted'),
				)) {
				return
			}
			this.run(() => api.respondOffer(offer.id, response))
		},
		async withdraw(offer) {
			if (!await confirmDestructive(
				this.t('recruiting', 'Withdraw this offer?'),
				this.t('recruiting', 'The offer can no longer be accepted; the candidate keeps their stage.'),
				this.t('recruiting', 'Withdraw offer'),
			)) {
				return
			}
			this.run(() => api.withdrawOffer(offer.id))
		},
	},
}
</script>

<style scoped lang="scss">
.offer-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding-block: calc(var(--default-grid-baseline) * 2);

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;

		&--start {
			justify-content: flex-start;
		}
	}
}

.offer-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3);

	h3 {
		margin: 0;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		margin: 0;
	}

	&__row {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);

		> * {
			flex: 1;
		}
	}

	&__period {
		min-width: 130px;
	}
}

.offer {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&--past {
		opacity: 0.65;
	}

	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__status {
		border-radius: var(--border-radius-pill);
		padding: 2px 10px;
		font-size: 0.85em;
		background-color: var(--color-background-hover);
		white-space: nowrap;

		&--accepted {
			background-color: var(--color-success-hover, var(--color-background-hover));
		}

		&--pending_approval,
		&--sent,
		&--negotiating {
			background-color: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}
	}

	&__facts {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);

		div {
			display: grid;
			grid-template-columns: 110px 1fr;
			gap: calc(var(--default-grid-baseline) * 2);
		}

		dt {
			color: var(--color-text-maxcontrast);
			text-align: start;
		}

		dd {
			white-space: pre-wrap;
		}
	}

	&__workflow {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		border-top: 1px solid var(--color-border);
		padding-top: calc(var(--default-grid-baseline) * 2);
	}

	&__submit {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);

		> :first-child {
			flex: 1;
		}
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 0;
		font-size: 0.9em;
	}
}
</style>
