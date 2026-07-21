<?php

/**
 * @file
 * A stale palette/name write through update() answers 409, not silent overwrite.
 *
 * Nisha's second adversarial scenario for e0442c: Alain adds the tag « Bloqué »,
 * Steve — whose editor held the palette from before — saves his own colour, and
 * « Bloqué » vanishes though neither touched the other's tag. update() sent the
 * WHOLE palette, so the last writer won. Same rev guard as the columns closes it.
 *
 * RED before update() takes baseRev; GREEN after.
 *
 * Usage: runuser -u www-data -- php board_update_conflict.php   ·   0 ok · 1 fail · 2 setup.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
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
$controller = \OC::$server->get(BoardController::class);

$board = Board::create('zzz Update Conflict', '#0082c9');
$repo->create($board);
$bid = $board->id;

try {
	$rev0 = $repo->find($bid)->rev;

	// Alain adds « Bloqué », quoting the rev he read.
	$a = $controller->update($bid, null, null, [['name' => 'Bloqué', 'color' => '#ff0000']], null, $rev0);
	check('[1] la première écriture de palette réussit', $a->getStatus() === 200, 'statut: ' . $a->getStatus());

	// Steve, holding the OLD palette, saves his own colour — same stale rev.
	$stale = $controller->update($bid, null, '#00ff00', [], null, $rev0);
	check('[2] la palette périmée répond 409', $stale->getStatus() === 409, 'statut: ' . $stale->getStatus());

	$tags = array_column($repo->find($bid)->tags, 'name');
	check(
		'[3] « Bloqué » a survécu (pas d’écrasement silencieux)',
		in_array('Bloqué', $tags, true),
		'palette: ' . (implode(', ', $tags) ?: '(vide)'),
	);

	$noRev = $controller->update($bid, 'zzz renommé sans rev');
	check(
		'[4] sans baseRev, l’écriture passe toujours',
		$noRev->getStatus() === 200 && $repo->find($bid)->name === 'zzz renommé sans rev',
		'statut: ' . $noRev->getStatus(),
	);
} finally {
	$repo->delete($bid);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
