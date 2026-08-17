<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcSettingsSection
			:name="t('recruiting', 'Recruiting')"
			:description="t('recruiting', 'Who runs recruiting and how candidate mail is sent.')">
			<div class="admin-recruiting__form">
				<NcSelect
					v-model="hrGroup"
					:options="groups"
					:inputLabel="t('recruiting', 'HR group')"
					label="displayName"
					:clearable="false" />
				<p class="admin-recruiting__hint">
					{{ t('recruiting', 'Members of this group are recruiters: they manage openings, templates, the triage inbox and can delete candidate data.') }}
				</p>
				<NcTextField v-model="settings.senderName" :label="t('recruiting', 'Sender name')" :placeholder="t('recruiting', 'e.g. HR team')" />
				<NcTextField v-model="settings.senderEmail" type="email" :label="t('recruiting', 'Sender email address')" :placeholder="t('recruiting', 'e.g. jobs@example.com')" />
				<p class="admin-recruiting__hint">
					{{ t('recruiting', 'All candidate mail (invitations, rejections, offers) is sent with this identity via the server’s email settings. Replies go to the application mailbox below, so answers land back in the app.') }}
				</p>
				<NcTextField v-model.number="settings.retentionDays" type="number" min="0" max="3650" :label="t('recruiting', 'Retention period (days)')" />
				<p class="admin-recruiting__hint">
					{{ t('recruiting', 'Rejected and withdrawn candidates are anonymized automatically after this many days (GDPR). 0 disables the automation; deleting on request is always possible from the candidate view.') }}
				</p>
				<NcTextField v-model.number="settings.poolRetentionMonths" type="number" min="1" max="120" :label="t('recruiting', 'Talent pool membership (months)')" />
				<p class="admin-recruiting__hint">
					{{ t('recruiting', 'How long a talent-pool consent stays valid. Expired members are anonymized automatically.') }}
				</p>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('recruiting', 'Application mailbox')"
			:description="t('recruiting', 'A dedicated IMAP mailbox that is fetched every five minutes. Every unseen mail becomes a candidate; plus-addressing (jobs+opening-tag@…) routes it into the right opening.')">
			<div class="admin-recruiting__form">
				<NcCheckboxRadioSwitch v-model="settings.imapEnabled" type="switch">
					{{ t('recruiting', 'Fetch applications from the mailbox') }}
				</NcCheckboxRadioSwitch>
				<div class="admin-recruiting__row">
					<NcTextField v-model="settings.imapHost" :label="t('recruiting', 'IMAP host')" :placeholder="'imap.example.com'" />
					<NcTextField v-model.number="settings.imapPort" type="number" :label="t('recruiting', 'Port')" />
					<NcSelect
						v-model="imapSecurity"
						:options="securityOptionsList"
						:inputLabel="t('recruiting', 'Security')"
						label="label"
						:clearable="false" />
				</div>
				<div class="admin-recruiting__row">
					<NcTextField v-model="settings.imapUser" :label="t('recruiting', 'Mailbox user')" :placeholder="'jobs@example.com'" />
					<NcPasswordField
						v-model="imapPassword"
						:label="t('recruiting', 'Password')"
						:placeholder="settings.imapPasswordSet ? t('recruiting', '•••••••• (unchanged)') : ''" />
				</div>
				<div class="admin-recruiting__buttons">
					<NcButton :disabled="testing" @click="test">
						<template #icon>
							<NcLoadingIcon v-if="testing" :size="20" />
							<ConnectionIcon v-else :size="20" />
						</template>
						{{ t('recruiting', 'Test connection') }}
					</NcButton>
					<NcButton variant="primary" :disabled="saving" @click="save">
						{{ t('recruiting', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConnectionIcon from 'vue-material-design-icons/Connection.vue'
import api, { errorMessage } from '../api.js'

export default {
	name: 'AdminSettings',
	components: {
		ConnectionIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcPasswordField,
		NcSelect,
		NcSettingsSection,
		NcTextField,
	},
	data() {
		return {
			settings: {
				hrGroup: '',
				senderName: '',
				senderEmail: '',
				imapEnabled: false,
				imapHost: '',
				imapPort: 993,
				imapSecurity: 'ssl',
				imapUser: '',
				imapPasswordSet: false,
			},
			imapPassword: '',
			hrGroup: null,
			imapSecurity: null,
			groups: [],
			saving: false,
			testing: false,
		}
	},
	computed: {
		securityOptionsList() {
			return [
				{ id: 'ssl', label: 'SSL/TLS' },
				{ id: 'starttls', label: 'STARTTLS' },
				{ id: 'none', label: this.t('recruiting', 'None (insecure)') },
			]
		},
	},
	async created() {
		try {
			const [{ data: settings }, { data: groups }] = await Promise.all([api.getSettings(), api.getGroups()])
			this.settings = settings
			this.groups = groups.map((group) => ({ id: group.gid, displayName: group.displayName }))
			this.hrGroup = this.groups.find((group) => group.id === settings.hrGroup)
				?? { id: settings.hrGroup, displayName: settings.hrGroup }
			this.imapSecurity = this.securityOptionsList.find((option) => option.id === settings.imapSecurity)
		} catch (error) {
			showError(errorMessage(error, this.t('recruiting', 'Could not load the settings')))
		}
	},
	methods: {
		async save() {
			this.saving = true
			try {
				const payload = {
					...this.settings,
					hrGroup: this.hrGroup?.id ?? '',
					imapSecurity: this.imapSecurity?.id ?? 'ssl',
				}
				if (this.imapPassword !== '') {
					payload.imapPassword = this.imapPassword
				}
				const { data } = await api.updateSettings(payload)
				this.settings = data
				this.imapPassword = ''
				showSuccess(this.t('recruiting', 'Settings saved'))
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not save the settings')))
			} finally {
				this.saving = false
			}
		},
		async test() {
			this.testing = true
			try {
				await api.testImap({
					imapHost: this.settings.imapHost,
					imapPort: this.settings.imapPort,
					imapSecurity: this.imapSecurity?.id ?? 'ssl',
					imapUser: this.settings.imapUser,
					imapPassword: this.imapPassword,
				})
				showSuccess(this.t('recruiting', 'Connection successful — the mailbox is reachable'))
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Connection failed')))
			} finally {
				this.testing = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.admin-recruiting__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	max-width: 600px;
}

.admin-recruiting__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.admin-recruiting__row {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);

	> * {
		flex: 1;
	}
}

.admin-recruiting__buttons {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
