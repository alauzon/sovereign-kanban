<?php

/**
 * @file
 * The card DETAIL endpoint must carry linked_board, like the list does.
 *
 * A card has two serializers: Card::toArray() for the list, and the hand-written
 * CardController::detail() for GET /cards/{id} — the one the editor calls when
 * you open a card. linked_board was added to toArray() and to update(), but not
 * to detail(). Consequences, both real (Alain, 2026-07-20):
 *
 *   1. the « Tableau lié » field is empty every time you reopen the card;
 *   2. worse, the editor sends back what it read — an empty string — and update()
 *      reads '' as « unlink ». Editing the title of a linked card silently drops
 *      its link.
 *
 * RED before 'linked_board' is added to detail(), GREEN after.
 *
 * Usage: runuser -u www-data -- php linked_board_detail.php   ·   0 ok · 1 fail · 2 setup.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;
use Ramsey\Uuid\Uuid;

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

$host = Board::create('zzz Detail Host', '#0082c9');
$target = Board::create('zzz Detail Target', '#0082c9');
$boardRepo->create($host);
$boardRepo->create($target);

try {
	$cardRepo = new FileCardRepository(new NextcloudStorage($kanban->get($host->id)));
	$columns = $boardRepo->find($host->id)->columns;
	$card = new Card(
		id: Uuid::uuid4()->toString(),
		title: 'zzz carte liée',
		column: (string) reset($columns),
		created_at: new DateTime(),
		author: $uid,
		linked_board: $target->id,
	);
	$cardRepo->save($card);

	$controller = \OC::$server->get(CardController::class);

	$shown = $controller->show($host->id, $card->id)->getData()['card'] ?? [];
	check(
		'[1] GET /cards/{id} porte la clé linked_board',
		array_key_exists('linked_board', $shown),
		'clés: ' . implode(', ', array_keys($shown)),
	);
	check(
		'[2] et sa valeur est le tableau lié',
		($shown['linked_board'] ?? null) === $target->id,
		'reçu: ' . var_export($shown['linked_board'] ?? null, true),
	);

	// The editor echoes back what it read. Whatever detail() omits, it sends as ''
	// — which update() reads as « unlink ». This is the data-loss half of the bug.
	$controller->update(
		boardId: $host->id,
		cardId: $card->id,
		title: 'zzz carte liée (titre modifié)',
		linked_board: (string) ($shown['linked_board'] ?? ''),
	);
	$after = $cardRepo->findById($card->id);
	check(
		'[3] renvoyer ce que l’éditeur a lu ne délie pas la carte',
		$after !== null && $after->linked_board === $target->id,
		'après enregistrement: ' . var_export($after?->linked_board, true),
	);
} finally {
	$kanban->get($host->id)->delete();
	$kanban->get($target->id)->delete();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
