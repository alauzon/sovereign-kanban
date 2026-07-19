<?php

/**
 * @file
 * Fixture for vue-assignees.spec.js: puts the two test accounts in a shared
 * group so the sharees autocomplete (mode "group") can suggest one to the other.
 * Run as www-data on CT 211.
 *
 * Usage:
 *   php assignee-fixture.php setup     → creates the group, adds Test 1 + Test 2
 *   php assignee-fixture.php teardown  → deletes the group
 *
 * SAFETY: a dedicated zzz-e2e group with the two test accounts only; never
 * touches real members or their groups.
 */

require_once '/var/www/nextcloud/lib/base.php';

$GRP = 'zzz-e2e-assignees-grp';
$USERS = ['Test 1', 'Test 2'];
$action = $argv[1] ?? '';

$gm = \OC::$server->get(\OCP\IGroupManager::class);
$um = \OC::$server->get(\OCP\IUserManager::class);

if ($action === 'setup') {
	$g = $gm->groupExists($GRP) ? $gm->get($GRP) : $gm->createGroup($GRP);
	foreach ($USERS as $uid) {
		$u = $um->get($uid);
		if ($u !== null) {
			$g->addUser($u);
		}
	}
	fwrite(STDOUT, "ok\n");
} elseif ($action === 'teardown') {
	if ($gm->groupExists($GRP)) {
		$gm->get($GRP)->delete();
	}
	fwrite(STDOUT, "ok\n");
} else {
	fwrite(STDERR, "usage: setup|teardown\n");
	exit(2);
}
