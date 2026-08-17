<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Recruiting\Exception;

/**
 * Thrown on invalid user input. Controllers translate this into a 400
 * response carrying the (already translated) message.
 */
class ValidationException extends \Exception {
}
