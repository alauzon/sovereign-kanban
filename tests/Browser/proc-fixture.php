<?php

/**
 * @file
 * Fixture for vue-procedures.spec.js: deposits a process snippet under the test
 * user's Files/Kanban/Procédures/ so the "+ Procédure" menu has something to
 * insert. Run as www-data on CT 211.
 *
 * Usage:
 *   php proc-fixture.php setup     → writes the procedure .md
 *   php proc-fixture.php teardown  → removes it
 *
 * SAFETY: single file, prefixed zzz-e2e, under the Test 1 account only.
 */

require_once '/var/www/nextcloud/lib/base.php';

$USER = 'Test 1';
$NAME = 'zzz-e2e-proc.md';
$BODY = "## Procédure e2e\n\n- étape un\n- étape deux\n";
$action = $argv[1] ?? '';

$rootFolder = \OC::$server->get(\OCP\Files\IRootFolder::class);
$userFolder = $rootFolder->getUserFolder($USER);

$kanban = $userFolder->nodeExists('Kanban')
	? $userFolder->get('Kanban')
	: $userFolder->newFolder('Kanban');
$proc = $kanban->nodeExists('Procédures')
	? $kanban->get('Procédures')
	: $kanban->newFolder('Procédures');

if ($action === 'setup') {
	if ($proc->nodeExists($NAME)) {
		$proc->get($NAME)->delete();
	}
	$proc->newFile($NAME)->putContent($BODY);
	fwrite(STDOUT, "ok\n");
} elseif ($action === 'teardown') {
	if ($proc->nodeExists($NAME)) {
		$proc->get($NAME)->delete();
	}
	fwrite(STDOUT, "ok\n");
} else {
	fwrite(STDERR, "usage: setup|teardown\n");
	exit(2);
}
