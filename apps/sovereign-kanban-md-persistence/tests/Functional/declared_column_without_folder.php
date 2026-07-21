<?php

/**
 * @file
 * A column declared in .board.yml must accept cards even with no folder yet.
 *
 * Steve, 2026-07-20: on his board « Développement Kanban souverain », typing a
 * title into « Terminé » or « Archivé » and pressing Enter did nothing at all —
 * while every other column worked. He guessed the columns were empty when the
 * board was created, and he was right about the shape of it.
 *
 * The columns exist in .board.yml but have NO folder on disk (the Deck import
 * only creates folders for stacks that hold cards). resolveColumnFolder() walks
 * the folders on disk, so it returns null, and create() answers 400
 * invalid_column — a silent failure in the UI. Verified: Steve's board is
 * missing « Terminé » and « Archivé »; Alain's « 3090 - Transcription » on ET is
 * missing « En file » and « En cours ».
 *
 * A declared column is a real column. Its folder is materialized on first use,
 * at the position .board.yml gives it.
 *
 * RED before resolveOrCreateColumnFolder(), GREEN after.
 *
 * Usage: runuser -u www-data -- php declared_column_without_folder.php   ·   0 ok · 1 fail · 2 setup.
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
$boardRepo = new FileBoardRepository(new NextcloudStorage($kanban));

$board = Board::create('zzz Colonne Sans Dossier', '#0082c9');
$boardRepo->create($board);
$bid = $board->id;

try {
	$boardFolder = $kanban->get($bid);
	$columns = $boardRepo->find($bid)->columns;
	$orphan = (string) end($columns);

	// Reproduce what the Deck import leaves behind: the column is declared, its
	// folder is not there.
	foreach ($boardFolder->getDirectoryListing() as $node) {
		if ($node instanceof \OCP\Files\Folder && preg_replace('/^\d+-/', '', $node->getName()) === $orphan) {
			$node->delete();
		}
	}

	$cardRepo = new FileCardRepository(new NextcloudStorage($boardFolder));
	check(
		'[1] la colonne est bien déclarée mais sans dossier (mise en situation)',
		in_array($orphan, $columns, true) && $cardRepo->resolveColumnFolder($orphan) === null,
		'colonne: ' . $orphan,
	);

	$controller = \OC::$server->get(CardController::class);
	$res = $controller->create($bid, 'zzz carte en colonne orpheline', $orphan);
	check(
		'[2] créer une carte dans cette colonne réussit',
		$res->getStatus() === 201,
		'statut: ' . $res->getStatus() . ' ' . json_encode($res->getData(), JSON_UNESCAPED_UNICODE),
	);

	$folder = (new FileCardRepository(new NextcloudStorage($boardFolder)))->resolveColumnFolder($orphan);
	check('[3] le dossier de la colonne a été créé', $folder !== null, 'dossier: ' . var_export($folder, true));
	check(
		'[4] il porte la position que lui donne .board.yml',
		$folder !== null && str_starts_with($folder, sprintf('%02d-', array_search($orphan, $columns, true) + 1)),
		'attendu le préfixe ' . sprintf('%02d-', (int) array_search($orphan, $columns, true) + 1) . ', obtenu ' . var_export($folder, true),
	);

	$fresh = new FileCardRepository(new NextcloudStorage($boardFolder));
	$titles = [];
	foreach ($fresh->listByColumn() as $col => $cards) {
		foreach ($cards as $c) {
			$titles[$c->title] = preg_replace('/^\d+-/', '', (string) $col);
		}
	}
	check(
		'[5] et la carte est visible dans cette colonne',
		($titles['zzz carte en colonne orpheline'] ?? null) === $orphan,
		'trouvée dans: ' . var_export($titles['zzz carte en colonne orpheline'] ?? null, true),
	);
} finally {
	$kanban->get($bid)->delete();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
