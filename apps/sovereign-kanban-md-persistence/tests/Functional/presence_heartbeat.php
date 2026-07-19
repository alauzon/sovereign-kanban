<?php

/**
 * @file
 * Functional test: board presence (Alain, 2026-07-19). A heartbeat records the
 * current user as viewing a board; the response lists the viewers currently
 * present. Two users → both present.
 *
 * Usage: runuser -u www-data -- php /tmp/presence_heartbeat.php
 * Exit codes: 0 passed · 1 failed · 70 died · 2 no account.
 *
 * SAFETY: uses a throwaway board id in the distributed cache only (no files);
 * the cache entry is removed at the end.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\PresenceController;

$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — NOT a pass.\n");
		exit(70);
	}
});

$BOARD = 'zzz-e2e-presence';
$server = \OC::$server;
$um = $server->get(\OCP\IUserManager::class);
$us = $server->get(\OCP\IUserSession::class);

$pass = 0;
$fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
	global $pass, $fail;
	$pass += $ok ? 1 : 0;
	$fail += $ok ? 0 : 1;
	printf("%s %-50s %s\n", $ok ? "\e[32m✅\e[0m" : "\e[31m❌\e[0m", $l, $d);
}

function actAs(string $uid): bool {
	$u = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
	if ($u === null) {
		return false;
	}
	\OC::$server->get(\OCP\IUserSession::class)->setUser($u);
	\OC_Util::setupFS($uid);
	return true;
}

if (!actAs('Test 1')) {
	fwrite(STDERR, "no account\n");
	exit(2);
}

try {
	$ctrl = $server->get(PresenceController::class);

	$v1 = $ctrl->heartbeat($BOARD)->getData()['viewers'] ?? [];
	check('[self] a heartbeat lists the current user present', in_array('Test 1', $v1, true), json_encode($v1));

	$hasTest2 = actAs('Test 2');
	if ($hasTest2) {
		$v2 = $ctrl->heartbeat($BOARD)->getData()['viewers'] ?? [];
		check('[both] a second viewer sees both present', in_array('Test 1', $v2, true) && in_array('Test 2', $v2, true), json_encode($v2));
	} else {
		check('[both] (skipped — no Test 2 account)', true, '');
	}

	printf("\n%d passed, %d failed\n", $pass, $fail);
} catch (\Throwable $e) {
	fwrite(STDOUT, 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
	$fail++;
} finally {
	try {
		$server->get(\OCP\ICacheFactory::class)->createDistributed('sk-presence-')->remove($BOARD);
	} catch (\Throwable $e) {
		// best effort
	}
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
