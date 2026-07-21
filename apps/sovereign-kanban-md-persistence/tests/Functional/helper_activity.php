<?php

/**
 * @file
 * Everything the helper writes must land in the card's activity journal.
 *
 * CardController calls appendActivity at ten places; sk_kanban.php called it at
 * none. So every card Claude created, every comment, every priority and every
 * relation was invisible in the Activité tab — a journal that shows only the
 * human's edits does not read as incomplete, it reads as "only the human acted".
 * Alain caught it on card a4e8b0, 2026-07-20.
 *
 * RED before the appendActivity calls in the helper, GREEN after.
 *
 * Usage: runuser -u www-data -- php helper_activity.php   ·   0 ok · 1 fail · 2 setup.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;

const HELPER = '/usr/local/bin/sk_kanban.php';

$uid = 'Alain Lauzon';
$user = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
if ($user === null) {
	fwrite(STDERR, "FATAL: compte '$uid' absent\n");
	exit(2);
}
if (!is_file(HELPER)) {
	fwrite(STDERR, 'FATAL: helper absent: ' . HELPER . "\n");
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

/** Run one helper command as the current user, returning its stdout. */
function helper(string $boardId, array $args): string {
	global $uid;
	$cmd = 'php ' . escapeshellarg(HELPER) . ' --user ' . escapeshellarg($uid);
	foreach ($args as $a) {
		$cmd .= ' ' . escapeshellarg($a);
	}

	return (string) shell_exec($cmd . ' 2>&1');
}

$root = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$kanban = $root->nodeExists('Kanban') ? $root->get('Kanban') : $root->newFolder('Kanban');
$boardRepo = new FileBoardRepository(new NextcloudStorage($kanban));
$board = Board::create('zzz Helper Activity Test', '#0082c9');
$boardRepo->create($board);
$bid = $board->id;

try {
	// Whatever the default columns are, use the board's own first and last.
	$columns = $boardRepo->find($bid)->columns;
	$firstColumn = (string) reset($columns);
	$lastColumn = (string) end($columns);

	// The helper creates the card, so `created` is itself under test.
	$out = helper($bid, ['add', $bid, $firstColumn, 'zzz carte de test']);
	if (!preg_match('/^(\w{6})\s/m', $out, $m)) {
		fwrite(STDERR, "FATAL: add n'a pas rendu d'id\n$out\n");
		exit(2);
	}
	$short = $m[1];

	helper($bid, ['comment', $bid, $short, 'commentaire de test']);
	helper($bid, ['priority', $bid, $short, '1']);
	helper($bid, ['tag', $bid, $short, 'add', 'zzz-test']);
	helper($bid, ['move', $bid, $short, 'Terminé']);

	$cardRepo = new FileCardRepository(new NextcloudStorage($kanban->get($bid)));
	$full = null;
	foreach ($cardRepo->listByColumn() as $cards) {
		foreach ($cards as $c) {
			if (str_starts_with($c->id, $short)) {
				$full = $c->id;
			}
		}
	}
	if ($full === null) {
		fwrite(STDERR, "FATAL: carte $short introuvable après les commandes\n");
		exit(2);
	}

	$events = $cardRepo->listActivity($full);
	$actions = array_map(static fn (array $e): string => (string) ($e['action'] ?? ''), $events);
	$seen = static fn (string $a): bool => in_array($a, $actions, true);
	$trace = 'journal: ' . (implode(', ', $actions) ?: '(vide)');

	check('[1] « add » journalise created', $seen('created'), $trace);
	check('[2] « comment » journalise commented', $seen('commented'), $trace);
	check('[3] « priority » journalise updated', $seen('updated'), $trace);
	check('[4] « tag » journalise updated', count(array_keys($actions, 'updated', true)) >= 2, $trace);
	check('[5] « move » journalise moved', $seen('moved'), $trace);
	check('[6] chaque événement porte son acteur', $events !== [] && !in_array(null, array_column($events, 'actor'), true), $trace);
} finally {
	$kanban->get($bid)->delete();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
