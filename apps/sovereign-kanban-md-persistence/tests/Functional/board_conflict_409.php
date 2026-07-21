<?php

/**
 * @file
 * A stale structural write through the CONTROLLER answers HTTP 409, not silence.
 *
 * Level « detect », wired end to end (Nisha, e0442c): the mutators carry
 * expectedRev, the controller maps BoardConflictException to 409 with the current
 * rev so the client can reload and replay. A caller that sends no baseRev still
 * writes (backward compatible with the helper and scripts).
 *
 * RED before baseRev/409 exist; GREEN after.
 *
 * Usage: runuser -u www-data -- php board_conflict_409.php   ·   0 ok · 1 fail · 2 setup.
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

$board = Board::create('zzz Conflict 409', '#0082c9');
$repo->create($board);
$bid = $board->id;

try {
	$rev0 = $repo->find($bid)->rev;

	$ok = $controller->addColumn($bid, 'zzz-a', $rev0);
	check('[1] une écriture à jour réussit', $ok->getStatus() === 201, 'statut: ' . $ok->getStatus());

	$stale = $controller->addColumn($bid, 'zzz-b', $rev0);
	check('[2] une écriture périmée répond 409', $stale->getStatus() === 409, 'statut: ' . $stale->getStatus());
	$data = $stale->getData();
	check(
		'[3] le 409 porte la révision courante pour recharger',
		($data['error'] ?? '') === 'conflict' && ($data['rev'] ?? 0) >= 1,
		json_encode($data, JSON_UNESCAPED_UNICODE),
	);
	check(
		'[4] la colonne à jour a survécu au conflit',
		in_array('zzz-a', $repo->find($bid)->columns, true) && !in_array('zzz-b', $repo->find($bid)->columns, true),
		'colonnes: ' . implode(', ', $repo->find($bid)->columns),
	);

	$noRev = $controller->addColumn($bid, 'zzz-c');
	check(
		'[5] sans baseRev, l’écriture passe toujours (helper, scripts)',
		$noRev->getStatus() === 201 && in_array('zzz-c', $repo->find($bid)->columns, true),
		'statut: ' . $noRev->getStatus(),
	);
} finally {
	$repo->delete($bid);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
