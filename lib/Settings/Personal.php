<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Settings;

use OCA\Recruiting\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'recruiting-personal-settings');
		return new TemplateResponse(Application::APP_ID, 'personal-settings');
	}

	#[\Override]
	public function getSection(): string {
		return 'groupware';
	}

	#[\Override]
	public function getPriority(): int {
		return 70;
	}
}
