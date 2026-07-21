<?php

/**
 * @file
 * A stale structural write on a board must be refused, not silently applied.
 *
 * The 2026-07-20 incident (e0442c): two clients hold the board as it was, one adds
 * a column, the other writes back its own older view — and the addition vanishes
 * without a trace. Nisha's fix (level « detect »): a rev token, and save() refuses
 * a write whose base rev no longer matches disk, so the loss becomes a visible
 * conflict instead of a silent erase.
 *
 * RED on the code before rev/expectedRev exists (the second arg is ignored, no
 * exception, the concurrent column is gone). GREEN after.
 *
 * Usage: runuser -u www-data -- php board_concurrent_write.php   ·   0 ok · 1 fail · 2 setup.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;

$uid = 'Alain Lauzon';
$user = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
if ($user === null) {
	fwrite(STDERR, "FATAL: compte '$uid' absent\n");
	exit(2);
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($user);
\OC_Util::setupFS($uid);

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
	global $pass, $fail;
	echo ($ok ? "\e[32m✅\e[0m " : "\e[31m❌\e[0m ") . $label . ($detail !== '' ? "  $detail" : '') . "\n";
	$ok ? $pass++ : $fail++;
}

$root = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$kanban = $root->nodeExists('Kanban') ? $root->get('Kanban') : $root->newFolder('Kanban');
$repo = new FileBoardRepository(new NextcloudStorage($kanban));

$board = Board::create('zzz Concurrent Write', '#0082c9');
$repo->create($board);
$bid = $board->id;

try {
	// Two clients read the same state.
	$readA = $repo->find($bid);
	$readB = $repo->find($bid);

	// Writer A (Claude) adds a column, quoting the rev it read.
	$repo->save($readA->addColumn('zzz-nouvelle'), $readA->rev);
	$afterA = $repo->find($bid);
	check(
		'[1] la première écriture passe et ajoute la colonne',
		in_array('zzz-nouvelle', $afterA->columns, true),
		'colonnes: ' . implode(', ', $afterA->columns),
	);

	// Writer B (stale) writes back its OLD view — which never knew of the column —
	// still quoting the rev it read before A wrote.
	$threw = null;
	try {
		$repo->save($readB->withColumns(['Backlog']), $readB->rev);
	} catch (\Throwable $e) {
		$threw = $e;
	}
	check(
		'[2] l’écriture périmée est refusée (conflit)',
		$threw !== null && str_contains(get_class($threw), 'Conflict'),
		$threw !== null ? get_class($threw) : 'aucune exception levée',
	);

	$afterB = $repo->find($bid);
	check(
		'[3] la colonne concurrente survit au conflit',
		in_array('zzz-nouvelle', $afterB->columns, true),
		'colonnes: ' . implode(', ', $afterB->columns),
	);
	check(
		'[4] la révision est exposée à l’API et a bien monté',
		($afterB->toArray()['rev'] ?? -1) >= 1,
		'rev: ' . var_export($afterB->toArray()['rev'] ?? null, true),
	);

	// Level-null: an un-versioned caller (helper, scripts) still writes.
	$before = $repo->find($bid)->rev;
	$repo->save($repo->find($bid)->withName('zzz renommé'));
	$renamed = $repo->find($bid);
	check(
		'[5] une écriture sans rev attendue passe toujours (rétrocompat)',
		$renamed->name === 'zzz renommé' && $renamed->rev === $before + 1,
		'nom: ' . $renamed->name . ', rev: ' . $renamed->rev,
	);
} finally {
	$repo->delete($bid);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
