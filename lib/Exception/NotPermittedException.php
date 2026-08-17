<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Exception;

/**
 * Thrown when the acting user lacks the permission for an operation.
 * Controllers translate this into a 403 response.
 */
class NotPermittedException extends \Exception {
}
