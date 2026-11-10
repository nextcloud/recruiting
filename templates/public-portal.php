<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** @var array $_ */
/** @var \OCP\IL10N $l */
style('recruiting', 'public-portal');
?>
<div class="recruiting-portal">
<?php if ($_['notFound']): ?>
	<div class="recruiting-portal__card">
		<h2><?php p($l->t('This link is not valid')); ?></h2>
		<p><?php p($l->t('The link you followed does not exist or is no longer active. Please contact the recruiting team.')); ?></p>
	</div>
<?php else:
	$view = $_['view'];
	$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
	$route = static fn (string $name): string => $urlGenerator->linkToRoute('recruiting.portal.' . $name, ['token' => $_['token']]);
	?>
	<div class="recruiting-portal__card">
		<h2><?php p($l->t('Hello %s,', [$view['name']])); ?></h2>
		<?php if ($view['openingTitle'] !== null): ?>
			<p class="recruiting-portal__meta"><?php p($l->t('Your application for “%1$s” at %2$s', [$view['openingTitle'], $_['instanceName']])); ?></p>
		<?php else: ?>
			<p class="recruiting-portal__meta"><?php p($l->t('Your application at %s', [$_['instanceName']])); ?></p>
		<?php endif; ?>

		<?php if ($_['notice'] !== null): ?>
			<p class="recruiting-portal__notice" role="status"><?php p($_['notice']); ?></p>
		<?php endif; ?>
		<?php if (!empty($_['errors'])): ?>
			<div class="recruiting-portal__errors" role="alert">
				<?php foreach ($_['errors'] as $error): ?>
					<p><?php p($error); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="recruiting-portal__status recruiting-portal__status--<?php p($view['status']); ?>">
			<span class="recruiting-portal__status-label"><?php p($l->t('Status')); ?></span>
			<strong><?php p($view['statusLabel']); ?></strong>
			<span><?php p($view['statusText']); ?></span>
		</div>

		<?php if ($view['deletionRequestedAt'] !== null): ?>
			<p class="recruiting-portal__hint"><?php p($l->t('You asked for your data to be deleted on %s. The recruiting team will take care of it.', [(string)$l->l('date', $view['deletionRequestedAt'])])); ?></p>
		<?php endif; ?>
	</div>

	<?php if ($view['interviews'] !== []): ?>
		<div class="recruiting-portal__card">
			<h3><?php p($l->t('Your interviews')); ?></h3>
			<ul class="recruiting-portal__interviews">
				<?php foreach ($view['interviews'] as $interview): ?>
					<li>
						<strong><?php p($interview['title']); ?></strong>
						<?php if ($interview['confirmed']): ?>
							<span><?php p($l->t('%1$s at %2$s', [$interview['date'], $interview['time']])); ?></span>
							<a href="<?php p($interview['url']); ?>"><?php p($l->t('Details, calendar file and video link')); ?></a>
						<?php else: ?>
							<span><?php p($l->t('Waiting for you to pick a time')); ?></span>
							<a href="<?php p($interview['url']); ?>"><?php p($l->t('Pick a time')); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ($view['canUpload']): ?>
		<div class="recruiting-portal__card">
			<h3><?php p($l->t('Add documents')); ?></h3>
			<p class="recruiting-portal__hint"><?php p($l->t('For example an updated CV or a certificate. PDF, Word, OpenDocument, text or image files up to 20 MB; at most %s documents in total.', [(string)$view['maxDocuments']])); ?></p>
			<form method="post" action="<?php p($route('uploadDocument')); ?>" enctype="multipart/form-data" class="recruiting-portal__form">
				<input type="file" name="document" required accept=".pdf,.doc,.docx,.odt,.txt,.md,.png,.jpg,.jpeg,.webp">
				<button type="submit" class="primary"><?php p($l->t('Upload')); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<?php if ($view['canWithdraw']): ?>
		<div class="recruiting-portal__card">
			<h3><?php p($l->t('Withdraw your application')); ?></h3>
			<p class="recruiting-portal__hint"><?php p($l->t('If you are no longer interested, you can withdraw here. The hiring team will be informed.')); ?></p>
			<form method="post" action="<?php p($route('withdraw')); ?>" class="recruiting-portal__form">
				<label class="recruiting-portal__confirm">
					<input type="checkbox" name="confirm" value="on" required>
					<span><?php p($l->t('Yes, I want to withdraw my application.')); ?></span>
				</label>
				<button type="submit"><?php p($l->t('Withdraw application')); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<?php if ($view['deletionRequestedAt'] === null): ?>
		<div class="recruiting-portal__card">
			<h3><?php p($l->t('Delete your data')); ?></h3>
			<p class="recruiting-portal__hint"><?php p($l->t('You can ask us to delete your application and all your data. The recruiting team handles the request; once it is done, this page no longer works.')); ?></p>
			<form method="post" action="<?php p($route('requestDeletion')); ?>" class="recruiting-portal__form">
				<label class="recruiting-portal__confirm">
					<input type="checkbox" name="confirm" value="on" required>
					<span><?php p($l->t('Yes, please delete my data.')); ?></span>
				</label>
				<button type="submit"><?php p($l->t('Request deletion')); ?></button>
			</form>
		</div>
	<?php endif; ?>
<?php endif; ?>
</div>
