<?php

/**
 * @file
 * A stale card edit must be refused, not silently overwrite a concurrent one.
 *
 * Alain, 2026-07-21, reproduced live on a real card: « Allo » typed in window A,
 * « Quoi » in window B; reloading A showed « Allo » gone, replaced by « Quoi ».
 * The card write path (CardController::update) has no version guard, so the last
 * writer wins in silence — the card twin of e0442c. Same fix: a rev on the card,
 * refused with 409 when the base rev has moved.
 *
 * The controller's update() is called with baseRev as a 15th POSITIONAL argument:
 * on the current code PHP ignores the extra positional (no guard, B overwrites,
 * RED); once update() declares it, the guard fires (409, GREEN).
 *
 * Usage: runuser -u www-data -- php card_concurrent_write.php   ·   0 ok · 1 fail · 2 setup.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
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
$controller = \OC::$server->get(CardController::class);

$board = Board::create('zzz Card Concurrent', '#0082c9');
// Idempotent setup: a prior run that died before its finally (or was killed by a
// transient error) leaves the board behind, and create() would then throw
// BoardAlreadyExists on every later run. Delete any residue first (Alain,
// 2026-07-21 — the very « clean up before you write » lesson of the day).
if ($boards->find($board->id) !== null) {
	$boards->delete($board->id);
}
$boards->create($board);
$bid = $board->id;
$cardRepo = new FileCardRepository(new NextcloudStorage($kanban->get($bid)));
$cols = $boards->find($bid)->columns;
$firstCol = (string) ($cols[array_key_first($cols)] ?? 'Backlog');

try {
	$created = $controller->create($bid, 'zzz carte concurrente', $firstCol)->getData()['card'];
	$cid = $created['id'];

	// Both windows read the same card.
	$rev0 = $controller->show($bid, $cid)->getData()['card']['rev'] ?? null;

	// Window A writes « Allo », quoting the rev it read.
	$a = $controller->update($bid, $cid, null, 'Allo', null, null, null, null, null, null, null, null, null, null, $rev0);
	check('[1] la première édition réussit', $a->getStatus() === 200, 'statut: ' . $a->getStatus());

	// Window B writes « Quoi », still quoting the OLD rev.
	$b = $controller->update($bid, $cid, null, 'Quoi', null, null, null, null, null, null, null, null, null, null, $rev0);
	check('[2] la seconde édition, périmée, répond 409', $b->getStatus() === 409, 'statut: ' . $b->getStatus());

	$onDisk = $cardRepo->findById($cid)->description;
	check(
		'[3] « Allo » a survécu (pas d’écrasement silencieux)',
		$onDisk === 'Allo',
		'description sur disque: ' . var_export($onDisk, true),
	);

	// A caller sending no baseRev still writes (auto-save, helper).
	$noRev = $controller->update($bid, $cid, null, 'sans rev');
	check(
		'[4] sans baseRev, l’écriture passe toujours',
		$noRev->getStatus() === 200 && $cardRepo->findById($cid)->description === 'sans rev',
		'statut: ' . $noRev->getStatus(),
	);
} finally {
	$boards->delete($bid);
}

// Nextcloud buffers stdout in CLI and swallows fatals; flush so results (and any
// error) actually reach the terminal (Alain, 2026-07-21 — hours lost to this).
while (ob_get_level() > 0) {
	ob_end_flush();
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
