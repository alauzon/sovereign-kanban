<?php

/**
 * @file
 * The board's Activité tab needs one endpoint aggregating every card's journal.
 *
 * Steve, 2026-07-20, classes it phase 1: « la section activités, les logs, ça va
 * être important de le mettre aussi ». Each card already keeps its own
 * activity.jsonl; nothing reads them together.
 *
 * RED before card#boardActivity exists, GREEN after.
 *
 * Usage: runuser -u www-data -- php board_activity.php   ·   0 ok · 1 fail · 2 setup.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

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
$boardRepo = new FileBoardRepository(new NextcloudStorage($kanban));
$board = Board::create('zzz Board Activity', '#0082c9');
$boardRepo->create($board);
$bid = $board->id;

try {
	$controller = \OC::$server->get(CardController::class);
	$columns = $boardRepo->find($bid)->columns;
	$first = (string) reset($columns);

	$a = $controller->create($bid, 'zzz carte A', $first)->getData()['card']['id'];
	$b = $controller->create($bid, 'zzz carte B', $first)->getData()['card']['id'];
	$controller->addComment($bid, $b, 'un commentaire');

	check('[1] la méthode boardActivity existe', method_exists($controller, 'boardActivity'));
	if (!method_exists($controller, 'boardActivity')) {
		printf("\n%d passed, %d failed\n", $pass, $fail + 3);
		$kanban->get($bid)->delete();
		exit(1);
	}

	$events = $controller->boardActivity($bid)->getData()['activity'] ?? [];
	$actions = array_column($events, 'action');

	check('[2] elle agrège les journaux des DEUX cartes', count(array_unique(array_column($events, 'card'))) === 2, 'cartes vues: ' . count(array_unique(array_column($events, 'card'))));
	check('[3] elle porte created et commented', in_array('created', $actions, true) && in_array('commented', $actions, true), 'actions: ' . implode(', ', $actions));
	check('[4] chaque événement nomme sa carte', $events !== [] && !in_array(null, array_column($events, 'card_title'), true));

	$stamps = array_column($events, 'ts');
	$sorted = $stamps;
	rsort($sorted);
	check('[5] du plus récent au plus ancien', $stamps === $sorted, implode(' | ', array_slice($stamps, 0, 3)));
} finally {
	$kanban->get($bid)->delete();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
