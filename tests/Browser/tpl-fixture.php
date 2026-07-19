<?php

/**
 * @file
 * Fixture for vue-templates.spec.js: deposits a card template under the test
 * user's Files/Kanban/Modèles/ so the "📋 gabarit" menu has something to pick.
 * Run as www-data on CT 211.
 *
 * Usage:
 *   php tpl-fixture.php setup     → writes the template .md
 *   php tpl-fixture.php teardown  → removes it
 *
 * SAFETY: single file, prefixed zzz-e2e, under the Test 1 account only.
 */

require_once '/var/www/nextcloud/lib/base.php';

$USER = 'Test 1';
$NAME = 'zzz-e2e-tpl.md';
$CONTENT = "---\nicône: 📋\n---\nCorps du gabarit e2e\n";
$action = $argv[1] ?? '';

$rootFolder = \OC::$server->get(\OCP\Files\IRootFolder::class);
$userFolder = $rootFolder->getUserFolder($USER);

$kanban = $userFolder->nodeExists('Kanban')
	? $userFolder->get('Kanban')
	: $userFolder->newFolder('Kanban');
$models = $kanban->nodeExists('Modèles')
	? $kanban->get('Modèles')
	: $kanban->newFolder('Modèles');

if ($action === 'setup') {
	if ($models->nodeExists($NAME)) {
		$models->get($NAME)->delete();
	}
	$models->newFile($NAME)->putContent($CONTENT);
	fwrite(STDOUT, "ok\n");
} elseif ($action === 'teardown') {
	if ($models->nodeExists($NAME)) {
		$models->get($NAME)->delete();
	}
	fwrite(STDOUT, "ok\n");
} else {
	fwrite(STDERR, "usage: setup|teardown\n");
	exit(2);
}
