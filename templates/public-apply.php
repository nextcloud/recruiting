<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** @var array $_ */
/** @var \OCP\IL10N $l */
style('recruiting', 'public-apply');

/** @var \OCA\Recruiting\Db\Opening $opening */
$opening = $_['opening'];
$values = $_['values'];
$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
$action = $urlGenerator->linkToRoute('recruiting.public.submitApplication', ['slug' => $opening->getMailSlug()]);
?>
<div class="recruiting-apply">
	<?php if ($_['done']): ?>
		<div class="recruiting-apply__card recruiting-apply__done">
			<h2><?php p($l->t('Thank you for applying!')); ?></h2>
			<p><?php p($l->t('We have received your application for “%s” and will be in touch. You may close this page.', [$opening->getTitle()])); ?></p>
		</div>
	<?php else: ?>
		<div class="recruiting-apply__card">
			<h2><?php p($l->t('Apply: %s', [$opening->getTitle()])); ?></h2>
			<?php
			$meta = array_filter([$opening->getDepartment(), $opening->getLocation(), $opening->getEmploymentType()]);
		if ($meta !== []): ?>
				<p class="recruiting-apply__meta"><?php p(implode(' · ', $meta)); ?></p>
			<?php endif; ?>
			<?php if (trim((string)$opening->getDescription()) !== ''): ?>
				<div class="recruiting-apply__desc"><?php p($opening->getDescription()); ?></div>
			<?php endif; ?>

			<?php if (!empty($_['errors'])): ?>
				<div class="recruiting-apply__errors" role="alert">
					<?php foreach ($_['errors'] as $error): ?>
						<p><?php p($error); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php p($action); ?>" enctype="multipart/form-data" class="recruiting-apply__form">
				<input type="hidden" name="t" value="<?php p((string)$_['now']); ?>">
				<!-- Honeypot: hidden from people, tempting to bots. -->
				<div class="recruiting-apply__hp" aria-hidden="true">
					<label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
				</div>

				<label class="recruiting-apply__label" for="ra-name"><?php p($l->t('Your name')); ?> *</label>
				<input id="ra-name" type="text" name="name" required maxlength="250" value="<?php p($values['name'] ?? ''); ?>">

				<label class="recruiting-apply__label" for="ra-email"><?php p($l->t('Email address')); ?> *</label>
				<input id="ra-email" type="email" name="email" required maxlength="250" value="<?php p($values['email'] ?? ''); ?>">

				<label class="recruiting-apply__label" for="ra-cover"><?php p($l->t('Cover letter (optional)')); ?></label>
				<textarea id="ra-cover" name="cover" rows="6" maxlength="20000"><?php p($values['cover'] ?? ''); ?></textarea>

				<label class="recruiting-apply__label" for="ra-cv"><?php p($l->t('CV / résumé (optional)')); ?></label>
				<input id="ra-cv" type="file" name="cv" accept=".pdf,.doc,.docx,.odt,.txt,.png,.jpg,.jpeg">

				<label class="recruiting-apply__consent">
					<input type="checkbox" name="consent" value="on" required>
					<span><?php p($l->t('I agree that my application data is stored and processed for this recruitment. I can request deletion at any time.')); ?></span>
				</label>

				<button type="submit" class="primary"><?php p($l->t('Send application')); ?></button>
			</form>
		</div>
	<?php endif; ?>
</div>
