<?php

/**
 * @file
 * A card whose TITLE contains « / » must still be a normal, visible card.
 *
 * FileCardRepository::save() builds the card folder from the title
 * (str_replace(' ', '-')). A '/' survived that and became a PATH SEPARATOR:
 * "comment / priority / relate" created three NESTED folders and buried card.md
 * where listByColumn() — which expects <cardFolder>/card.md — could not see it.
 * The card vanished from the board while its file existed on disk (Alain,
 * 2026-07-20: two cards created by Claude did exactly that). Same class as the
 * column-rename « / » bug fixed the day before.
 *
 * RED before the slug is sanitized, GREEN after.
 *
 * Usage: runuser -u www-data -- php card_title_slash.php   ·   0 ok · 1 fail.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;
use Ramsey\Uuid\Uuid;

$uid = 'Test 1';
$u = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
if ($u === null) {
	fwrite(STDERR, "FATAL: compte '$uid' absent\n");
	exit(2);
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($u);
\OC_Util::setupFS($uid);

$root = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$kanban = $root->nodeExists('Kanban') ? $root->get('Kanban') : $root->newFolder('Kanban');
$boardRepo = new FileBoardRepository(new NextcloudStorage($kanban));

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
	global $pass, $fail;
	echo ($ok ? "\e[32m✅\e[0m " : "\e[31m❌\e[0m ") . $label . ($detail !== '' ? "  $detail" : '') . "\n";
	$ok ? $pass++ : $fail++;
}

$board = Board::create('zzz Slash Title Test', '#0082c9')->withColumns(['Alpha']);
$boardRepo->create($board);
$bid = $board->id;

try {
	$repo = new FileCardRepository(new NextcloudStorage($kanban->get($bid)));
	$folder = $repo->resolveColumnFolder('Alpha');
	$id = Uuid::uuid4()->toString();
	$title = 'commandes comment / priority / relate';
	$repo->save(new Card(id: $id, title: $title, column: $folder));

	$seen = [];
	foreach ($repo->listByColumn() as $cards) {
		foreach ($cards as $c) {
			$seen[] = $c->id;
		}
	}
	check('[1] une carte au titre contenant « / » est listée sur le tableau', in_array($id, $seen, true), 'vues: ' . count($seen));
	check('[2] findById la retrouve', $repo->findById($id) !== null);

	$found = $repo->findById($id);
	check('[3] son titre est intact (le « / » reste dans le titre)', $found !== null && $found->title === $title);
} finally {
	$kanban->get($bid)->delete();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
