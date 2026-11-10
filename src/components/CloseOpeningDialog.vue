<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('recruiting', 'Close this opening?')"
		:message="n(
			'recruiting',
			'%n candidate is still active. You can reject them now with the rejection email, or close the opening and leave them as they are.',
			'%n candidates are still active. You can reject them now with the rejection email, or close the opening and leave them as they are.',
			activeCount,
		)"
		size="normal"
		dialogClasses="close-opening-dialog"
		@update:open="!$event && $emit('cancel')">
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('recruiting', 'Cancel') }}
			</NcButton>
			<NcButton @click="$emit('closeOnly')">
				{{ t('recruiting', 'Close without changing them') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('reject')">
				{{ n('recruiting', 'Reject the remaining candidate …', 'Reject the %n remaining candidates …', activeCount) }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

/**
 * The "close opening" step: what happens to the candidates still in the
 * pipeline. Rejecting them opens the bulk reject dialog.
 */
export default {
	name: 'CloseOpeningDialog',
	components: {
		NcButton,
		NcDialog,
	},

	props: {
		activeCount: { type: Number, required: true },
	},

	emits: ['cancel', 'closeOnly', 'reject'],
}
</script>

<style lang="scss">
/* Three long button labels do not fit on one line in a normal-sized dialog */
.close-opening-dialog .dialog__actions {
	flex-wrap: wrap;
	justify-content: flex-end;
}
</style>
