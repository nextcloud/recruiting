<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** @var array $_ */
/** @var \OCP\IL10N $l */
style('recruiting', 'public-pool');
?>
<div class="recruiting-pool-consent">
	<?php if ($_['confirmed']): ?>
		<h2><?php p($l->t('Thank you, %s!', [$_['name']])); ?></h2>
		<p><?php p($l->t('We will keep your application on file and reach out when a fitting position opens up.')); ?></p>
		<p class="recruiting-pool-consent__hint">
			<?php p($l->t('You can request deletion of your data at any time by contacting the recruiting team.')); ?>
		</p>
	<?php else: ?>
		<h2><?php p($l->t('Hello %s,', [$_['name']])); ?></h2>
		<p><?php p($l->t('May we keep your application on file and consider you for future openings?')); ?></p>
		<p class="recruiting-pool-consent__hint">
			<?php p($l->t('If you agree, we will store your application documents and contact details for future recruiting purposes. Your data is deleted automatically after the retention period, and you can request deletion at any time.')); ?>
		</p>
		<form method="post" action="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('recruiting.public.confirmPoolConsent', ['token' => $_['token']])); ?>">
			<button type="submit" class="primary"><?php p($l->t('Yes, keep my application on file')); ?></button>
		</form>
	<?php endif; ?>
</div>
