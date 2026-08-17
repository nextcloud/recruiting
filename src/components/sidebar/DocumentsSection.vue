<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="documents-section">
		<h3>{{ t('recruiting', 'Documents') }}</h3>
		<p class="documents-section__hint">
			{{ t('recruiting', 'CVs and attachments are stored privately inside the app — they never appear in Files, and only people with access to this candidate can open them.') }}
		</p>
		<p v-if="candidate.documents.length === 0" class="documents-section__empty">
			{{ t('recruiting', 'No documents yet') }}
		</p>

		<ul v-else class="documents-section__list">
			<li v-for="document in candidate.documents" :key="document.id" class="documents-section__item">
				<a
					class="documents-section__link"
					:href="api.documentUrl(document.id)"
					target="_blank"
					rel="noopener">
					<FilePdfBox v-if="document.mime === 'application/pdf'" :size="24" />
					<FileImageOutline v-else-if="document.mime.startsWith('image/')" :size="24" />
					<FileDocumentOutline v-else :size="24" />
					<span class="documents-section__name">{{ document.name }}</span>
					<span class="documents-section__size">{{ formatSize(document.size) }}</span>
				</a>
				<NcButton
					v-if="candidate.permissions.manage"
					variant="tertiary"
					:ariaLabel="t('recruiting', 'Delete document')"
					@click="remove(document)">
					<template #icon>
						<TrashCanOutline :size="18" />
					</template>
				</NcButton>
			</li>
		</ul>

		<template v-if="candidate.permissions.manage">
			<NcButton :disabled="uploading" @click="$refs.fileInput.click()">
				<template #icon>
					<NcLoadingIcon v-if="uploading" :size="20" />
					<Upload v-else :size="20" />
				</template>
				{{ t('recruiting', 'Upload document') }}
			</NcButton>
			<input
				ref="fileInput"
				type="file"
				multiple
				accept=".pdf,.doc,.docx,.odt,.txt,.md,.png,.jpg,.jpeg,.webp"
				class="hidden-visually"
				@change="upload">
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileImageOutline from 'vue-material-design-icons/FileImageOutline.vue'
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import api, { errorMessage } from '../../api.js'
import { confirmDestructive } from '../../utils/confirm.js'
import { useSidebarStore } from '../../store.js'
import { formatSize } from '../../utils/format.js'

export default {
	name: 'DocumentsSection',
	components: {
		FileDocumentOutline,
		FileImageOutline,
		FilePdfBox,
		NcButton,
		NcLoadingIcon,
		TrashCanOutline,
		Upload,
	},
	props: {
		candidate: { type: Object, required: true },
	},
	setup() {
		return { sidebar: useSidebarStore(), api }
	},
	data() {
		return { uploading: false }
	},
	methods: {
		formatSize,
		async upload(event) {
			const files = Array.from(event.target.files ?? [])
			event.target.value = ''
			this.uploading = true
			try {
				for (const file of files) {
					try {
						await api.uploadDocument(this.candidate.id, file)
					} catch (error) {
						showError(errorMessage(error, this.t('recruiting', 'Could not upload {name}', { name: file.name })))
					}
				}
				this.sidebar.reload()
			} finally {
				this.uploading = false
			}
		},
		async remove(document) {
			if (!await confirmDestructive(
				this.t('recruiting', 'Delete "{name}"?', { name: document.name }),
				this.t('recruiting', 'The document is removed for everyone with access to this candidate.'),
				this.t('recruiting', 'Delete'),
			)) {
				return
			}
			try {
				await api.deleteDocument(document.id)
				this.sidebar.reload()
			} catch (error) {
				showError(errorMessage(error, this.t('recruiting', 'Could not delete the document')))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.documents-section {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
	padding-top: calc(var(--default-grid-baseline) * 3);

	h3 {
		margin: 0;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		margin: 0;
	}

	&__empty {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	&__list {
		align-self: stretch;
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__item {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
	}

	&__link {
		flex: 1;
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) * 2);
		border-radius: var(--border-radius-large);
		min-width: 0;

		&:hover {
			background-color: var(--color-background-hover);
		}
	}

	&__name {
		flex: 1;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__size {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}
}
</style>
