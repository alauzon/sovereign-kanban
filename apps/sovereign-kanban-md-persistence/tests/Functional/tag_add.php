<?php

/**
 * @file
 * Adding a tag must work — on a CARD and on the board PALETTE — including the
 * chained case (add one, then another) now that a rev guard sits on both paths.
 *
 * Alain, 2026-07-21: « l'ajout d'une étiquette ne fonctionne pas pour moi ». The
 * server side was verified fine on a received board; this pins BOTH tag-add paths
 * so a regression there can never pass silently again. Covers the enchaînement
 * (second add on a fresh rev), which is where a stale-rev false 409 would bite.
 *
 * Nextcloud buffers stdout in CLI and swallows fatals — flush so results (and any
 * error) reach the terminal (hours lost to this on 2026-07-21).
 *
 * Usage: runuser -u www-data -- php tag_add.php   ·   0 ok · 1 fail · 2 setup.
 */

while (ob_get_level() > 0) {
	ob_end_flush();
}
require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
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
$boards = new FileBoardRepository(new NextcloudStorage($kanban));
$cardCtl = \OC::$server->get(CardController::class);
$boardCtl = \OC::$server->get(BoardController::class);

$board = Board::create('zzz Tag Add', '#0082c9');
if ($boards->find($board->id) !== null) {
	$boards->delete($board->id);
}
$boards->create($board);
$bid = $board->id;
$cols = $boards->find($bid)->columns;
$firstCol = (string) ($cols[array_key_first($cols)] ?? 'Backlog');

try {
	// ---- CARD tag ----
	$card = $cardCtl->create($bid, 'zzz carte', $firstCol)->getData()['card'];
	$cid = $card['id'];

	$rev = $cardCtl->show($bid, $cid)->getData()['card']['rev'];
	$r1 = $cardCtl->update($bid, $cid, null, null, null, null, null, ['urgent'], null, null, null, null, null, null, $rev);
	check('[1] ajouter une étiquette à une carte réussit', $r1->getStatus() === 200, 'statut: ' . $r1->getStatus());
	$c1 = $cardCtl->show($bid, $cid)->getData()['card'];
	check('[2] l’étiquette est bien sur la carte', in_array('urgent', $c1['tags'], true), 'tags: ' . implode(',', $c1['tags']));

	// Chained add on the FRESH rev — the enchaînement that a stale-rev false 409
	// would break (the palette bug earlier today).
	$r2 = $cardCtl->update($bid, $cid, null, null, null, null, null, ['urgent', 'bloqué'], null, null, null, null, null, null, $c1['rev']);
	check('[3] ajouter une 2e étiquette (rev frais) réussit', $r2->getStatus() === 200, 'statut: ' . $r2->getStatus());
	$c2 = $cardCtl->show($bid, $cid)->getData()['card'];
	check('[4] les deux étiquettes sont là', count(array_intersect(['urgent', 'bloqué'], $c2['tags'])) === 2, 'tags: ' . implode(',', $c2['tags']));

	// ---- PALETTE tag ----
	$prev = $boards->find($bid)->rev;
	$p1 = $boardCtl->update($bid, null, null, [['name' => 'Feature', 'color' => '#00aa00']], null, $prev);
	check('[5] ajouter une étiquette à la palette réussit', $p1->getStatus() === 200, 'statut: ' . $p1->getStatus());
	$palette = array_column($boards->find($bid)->tags, 'name');
	check('[6] l’étiquette est dans la palette', in_array('Feature', $palette, true), 'palette: ' . implode(',', $palette));

	// Chained palette add on fresh rev.
	$fresh = $boards->find($bid)->rev;
	$p2 = $boardCtl->update($bid, null, null, [['name' => 'Feature', 'color' => '#00aa00'], ['name' => 'Bug', 'color' => '#aa0000']], null, $fresh);
	check('[7] ajouter une 2e étiquette de palette (rev frais) réussit', $p2->getStatus() === 200, 'statut: ' . $p2->getStatus());
	$palette2 = array_column($boards->find($bid)->tags, 'name');
	check('[8] les deux étiquettes de palette sont là', count(array_intersect(['Feature', 'Bug'], $palette2)) === 2, 'palette: ' . implode(',', $palette2));
} finally {
	$boards->delete($bid);
}

while (ob_get_level() > 0) {
	ob_end_flush();
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
