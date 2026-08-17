<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The unit tests are pure: they never boot the server. Only the app's own
 * classes and the OCP interfaces are autoloaded, so `composer run test:unit`
 * works in a checkout without a Nextcloud instance — as long as the app sits
 * inside a server tree (…/apps/recruiting) or OCP is installed through
 * vendor-bin/nextcloud-ocp.
 */
$appRoot = dirname(__DIR__);

if (file_exists($appRoot . '/vendor/autoload.php')) {
	require_once $appRoot . '/vendor/autoload.php';
}

$ocpCandidates = [
	$appRoot . '/vendor-bin/nextcloud-ocp/vendor/autoload.php',
	$appRoot . '/../../lib/public',   // app inside a server checkout
];

$serverPublic = null;
foreach ($ocpCandidates as $candidate) {
	if (str_ends_with($candidate, 'autoload.php') && file_exists($candidate)) {
		require_once $candidate;
		break;
	}
	if (is_dir($candidate)) {
		$serverPublic = realpath($candidate);
		break;
	}
}

// 3rdparty (Doctrine DBAL) is needed by the query-builder interfaces
$thirdParty = realpath($appRoot . '/../../3rdparty/autoload.php');
if ($thirdParty !== false) {
	require_once $thirdParty;
}

spl_autoload_register(static function (string $class) use ($appRoot, $serverPublic): void {
	$prefixes = [
		'OCA\\Recruiting\\Tests\\' => $appRoot . '/tests/',
		'OCA\\Recruiting\\' => $appRoot . '/lib/',
	];
	if ($serverPublic !== null) {
		$prefixes['OCP\\'] = $serverPublic . '/';
	}
	foreach ($prefixes as $prefix => $dir) {
		if (str_starts_with($class, $prefix)) {
			$path = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (file_exists($path)) {
				require_once $path;
			}
			return;
		}
	}
});
