<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="reports-view">
		<h2>{{ t('recruiting', 'Reports') }}</h2>
		<p class="reports-view__hint">
			{{ t('recruiting', 'Live numbers over the openings you have access to. Time-in-stage and time-to-hire are reconstructed from each candidate’s actual history; rejected candidates keep contributing anonymously even after GDPR anonymization.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="44" class="reports-view__loading" />
		<NcEmptyContent v-else-if="!report || report.openings.length === 0" :name="t('recruiting', 'Nothing to report yet')">
			<template #icon>
				<ChartBoxOutline />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Headline numbers -->
			<div class="reports-view__tiles">
				<div class="stat-tile">
					<span class="stat-tile__value">{{ report.totals.candidates }}</span>
					<span class="stat-tile__label">{{ t('recruiting', 'Candidates') }}</span>
					<span v-if="thisWeek > 0" class="stat-tile__trend">{{ t('recruiting', '+{count} this week', { count: thisWeek }) }}</span>
				</div>
				<div class="stat-tile">
					<span class="stat-tile__value">{{ report.totals.hired }}</span>
					<span class="stat-tile__label">{{ t('recruiting', 'Hired') }}</span>
					<span v-if="report.totals.hired > 0" class="stat-tile__trend stat-tile__trend--plain">🎉</span>
				</div>
				<div class="stat-tile" :title="t('recruiting', 'Average days from application to accepted offer, over all hired candidates')">
					<span class="stat-tile__value">{{ report.totals.avgDaysToHire ?? '–' }}</span>
					<span class="stat-tile__label">{{ t('recruiting', 'Avg. days to hire') }}</span>
				</div>
				<div class="stat-tile">
					<span class="stat-tile__value">{{ report.totals.openOffers }}</span>
					<span class="stat-tile__label">{{ t('recruiting', 'Open offers') }}</span>
				</div>
			</div>

			<!-- Applications per week -->
			<section class="reports-view__section">
				<h3>{{ t('recruiting', 'Applications per week') }}</h3>
				<div class="week-chart" role="img" :aria-label="t('recruiting', 'Applications per week, last 12 weeks')">
					<div
						v-for="(week, index) in report.applicationsPerWeek"
						:key="week.week"
						class="week-chart__col"
						:class="{ 'week-chart__col--current': index === report.applicationsPerWeek.length - 1 }"
						:title="week.week + ': ' + n('recruiting', '%n application', '%n applications', week.count)">
						<span class="week-chart__value">{{ week.count > 0 ? week.count : '' }}</span>
						<div class="week-chart__bar" :style="{ height: barHeight(week.count) }" />
						<span class="week-chart__label">{{ week.week.slice(5) }}</span>
					</div>
				</div>
			</section>

			<!-- Per opening -->
			<section v-for="opening in report.openings" :key="opening.id" class="reports-view__section opening-report">
				<div class="opening-report__head">
					<h3>
						<router-link :to="{ name: 'opening', params: { id: String(opening.id) } }">
							{{ opening.title }}
						</router-link>
					</h3>
					<span v-if="opening.status !== 'open'" class="opening-report__status">{{ opening.status === 'closed' ? t('recruiting', 'Closed') : t('recruiting', 'On hold') }}</span>
					<span class="opening-report__kpis">
						<span v-if="opening.avgDaysToHire !== null">⏱️ {{ t('recruiting', '{days} days to hire', { days: opening.avgDaysToHire }) }}</span>
						<span v-if="opening.openOffers > 0">🤝 {{ n('recruiting', '%n open offer', '%n open offers', opening.openOffers) }}</span>
					</span>
				</div>

				<!-- Funnel -->
				<div class="funnel">
					<div v-for="stage in funnelStages" :key="stage" class="funnel__row">
						<span class="funnel__label">{{ stageLabel(stage) }}</span>
						<div class="funnel__track">
							<div
								class="funnel__bar"
								:class="'funnel__bar--' + stage"
								:style="{ width: funnelWidth(opening, stage) }" />
						</div>
						<span class="funnel__count">{{ opening.stageCounts[stage] ?? 0 }}</span>
						<span
							class="funnel__days"
							:title="t('recruiting', 'Average time candidates spend in this stage before moving on')">
							{{ opening.avgDaysInStage[stage] !== undefined
								? t('recruiting', 'avg {days} d', { days: opening.avgDaysInStage[stage] })
								: '' }}
						</span>
					</div>
				</div>

				<!-- Rejection reasons -->
				<div v-if="Object.keys(opening.rejectionReasons).length > 0" class="reasons">
					<span class="reasons__title">{{ t('recruiting', 'Rejection reasons:') }}</span>
					<span v-for="(count, reason) in opening.rejectionReasons" :key="reason" class="reasons__chip">
						{{ reasonLabel(reason) }} × {{ count }}
					</span>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import api, { errorMessage } from '../api.js'
import { useSessionStore } from '../store.js'
import { reasonLabel, stageLabel } from '../utils/format.js'

export default {
	name: 'ReportsView',
	components: { ChartBoxOutline, NcEmptyContent, NcLoadingIcon },
	setup() {
		return { session: useSessionStore() }
	},
	data() {
		return {
			report: null,
			loading: true,
		}
	},
	computed: {
		funnelStages() {
			return this.session.funnelStages
		},
		maxWeek() {
			return Math.max(1, ...(this.report?.applicationsPerWeek ?? []).map((w) => w.count))
		},
		thisWeek() {
			const weeks = this.report?.applicationsPerWeek ?? []
			return weeks.length > 0 ? weeks[weeks.length - 1].count : 0
		},
	},
	created() {
		this.load()
	},
	methods: {
		reasonLabel,
		stageLabel,
		async load() {
			this.loading = true
			try {
				const { data } = await api.getReports()
				this.report = data
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not load the reports')))
			} finally {
				this.loading = false
			}
		},
		barHeight(count) {
			return count === 0 ? '2px' : Math.max(6, Math.round((count / this.maxWeek) * 96)) + 'px'
		},
		funnelWidth(opening, stage) {
			const max = Math.max(1, ...this.funnelStages.map((s) => opening.stageCounts[s] ?? 0))
			const count = opening.stageCounts[stage] ?? 0
			return count === 0 ? '2px' : Math.max(2, Math.round((count / max) * 100)) + '%'
		},
	},
}
</script>

<style scoped lang="scss">
@use '../css/stages' as stages;

.reports-view {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 5);
	max-width: 1000px;

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

	&__tiles {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__section {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);

		h3 {
			margin: 0;
		}
	}
}

.stat-tile {
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: var(--default-grid-baseline);

	&__value {
		font-size: 2em;
		font-weight: 700;
		line-height: 1;
	}

	&__label {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__trend {
		color: var(--color-success-text, var(--color-success, #2d7b41));
		font-size: 0.85em;
		font-weight: 600;

		&--plain {
			color: inherit;
			font-weight: normal;
		}
	}
}

.week-chart {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	height: 150px;

	&__col {
		flex: 1;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: flex-end;
		gap: 4px;
		min-width: 0;
	}

	&__value {
		font-size: 0.8em;
		color: var(--color-text-maxcontrast);
		line-height: 1;
	}

	&__bar {
		width: 100%;
		max-width: 40px;
		background-color: var(--color-primary-element);
		border-radius: 4px 4px 0 0;
		opacity: 0.75;
		transition: opacity 0.15s ease;
	}

	&__col:hover &__bar,
	&__col--current &__bar {
		opacity: 1;
	}

	&__col--current &__label {
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__label {
		font-size: 0.75em;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}
}

.opening-report {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 4);

	&__head {
		display: flex;
		align-items: baseline;
		gap: calc(var(--default-grid-baseline) * 3);
		flex-wrap: wrap;

		a {
			text-decoration: none;

			&:hover {
				text-decoration: underline;
			}
		}
	}

	&__status {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}

	&__kpis {
		margin-inline-start: auto;
		display: flex;
		gap: calc(var(--default-grid-baseline) * 3);
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}
}

.funnel {
	@include stages.vars;

	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 1.5);

	&__row {
		display: grid;
		grid-template-columns: 110px 1fr 40px 90px;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__label {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		text-align: end;
	}

	&__track {
		background-color: var(--color-background-hover);
		border-radius: 4px;
		height: 20px;
		overflow: hidden;
	}

	&__bar {
		height: 100%;
		background-color: var(--color-primary-element);
		border-radius: 0 4px 4px 0;
		transition: width 0.3s ease;
		opacity: 0.85;

		// the same stage accents as the board columns
		&--new {
			background-color: var(--recruiting-stage-new);
		}

		&--screening {
			background-color: var(--recruiting-stage-screening);
		}

		&--interview {
			background-color: var(--recruiting-stage-interview);
		}

		&--offer {
			background-color: var(--recruiting-stage-offer);
		}

		&--hired {
			background-color: var(--recruiting-stage-hired);
		}
	}

	&__count {
		font-weight: 600;
		text-align: end;
	}

	&__days {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}
}

.reasons {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	margin-top: calc(var(--default-grid-baseline) * 2);

	&__title {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__chip {
		background-color: var(--color-background-hover);
		border-radius: var(--border-radius-pill);
		padding: 2px 10px;
		font-size: 0.85em;
	}
}
</style>
