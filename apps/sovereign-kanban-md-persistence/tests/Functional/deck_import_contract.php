<?php

/**
 * @file
 * Functional (e2e) test: what importing a Deck board must produce.
 *
 * Tshinanu → Tshinanu, on SYNTHETIC Deck data this script creates and deletes.
 * Decided by Alain 2026-07-17: no import/export between instances — copying ET's
 * Deck here would move 45 people's cards onto an instance with its own members.
 * The fixture reproduces the SHAPE of a real board (several stacks, due dates
 * with real times, labels, assignees, an archived card, a deleted one), not
 * anyone's content.
 *
 * WHY IT IS ONE TEST AND NOT SIX. The importer does not merely have a bug — it
 * cannot run at all, and has not been able to since 2026-06-06:
 *
 *   1. `new FileCardRepository($kanbanDir)` passes a STRING where a Storage is
 *      required — TypeError before touching any data. The Storage refactor
 *      (fbd7337) landed the day after the importer was finalized (5e95fed) and
 *      the importer never followed.
 *   2. It queries `deck_card_assignees` — a table that DOES NOT EXIST in Deck
 *      1.18.2. The real one is `deck_assigned_users`.
 *   3. `new \DateTime($deckCard['created_at'])` — created_at is an int(10)
 *      unsigned (Unix timestamp), and DateTime throws on it.
 *   4. `queryCards()` selects id, title, description, created_at only. The due
 *      date is NEVER ASKED FOR. (Deck stores it as a real DATETIME, time and
 *      all: '2021-12-10 21:00:00'.)
 *   5. `queryBoards()` has no WHERE at all — no owner, no deleted_at, no
 *      archived. On ET that is 77 boards from 45 owners into one person's folder.
 *   6. `mkdir()` writes raw filesystem, outside Nextcloud's storage layer.
 *   7. Card ids are Deck's numeric ids, not UUIDs as the format spec requires.
 *
 * So this is not a fix, it is a rewrite, and the test states the contract the
 * rewrite must meet. It is RED on purpose the first time it runs.
 *
 * ⚠️ THE TRAP THIS TEST CANNOT CATCH — read before trusting a green run.
 * ET is ENCRYPTED at rest; Tshinanu is NOT. Defect 6 (raw mkdir) is invisible
 * here: writing plaintext files works fine without encryption, and breaks on ET,
 * where Nextcloud would try to decrypt plaintext. A green run on Tshinanu says
 * NOTHING about ET unless the importer goes through the Storage API. That is why
 * "use Storage" is a precondition for this test meaning anything — not an
 * elegance. Same shape as the ET favicon/filecache incident.
 *
 * Scope rule asserted here (default chosen 2026-07-17, say so if you disagree):
 * import the boards the target user OWNS. Not deleted, not archived.
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/deck_import_contract.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: every Deck row it inserts is prefixed "zzz-e2e-deck" and deleted in the
 * finally block; SK boards that appear during the run are removed by diffing the
 * Kanban folder against a snapshot taken before the import. It creates data, it
 * never touches pre-existing Deck rows or SK boards.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

// The guard goes FIRST — before every require, including Nextcloud's own.
// Learned the hard way on 2026-07-17: this file used to install it after the
// requires, and a missing DeckImporter.php made the script die inside
// require_once with NO output and exit 0. A guard that is not armed yet guards
// nothing, and "silent exit 0" is precisely the failure it exists to catch.
$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — this test died before finishing. NOT a pass.\n");
		fwrite(STDERR, "   Teardown may NOT have run: check for zzz-e2e-deck* rows in oc_deck_* and stray SK boards.\n");
		exit(70);
	}
});

require_once '/var/www/nextcloud/lib/base.php';

// The import app is DISABLED, so its autoloader is not registered; load the
// class under test by hand. Checked explicitly because a require_once of a
// missing file, once base.php is loaded, is swallowed into a silent exit 0.
$importerFile = '/var/www/nextcloud/apps/sovereign-kanban-import/lib/Service/DeckImporter.php';
if (!file_exists($importerFile)) {
	fwrite(STDERR, "FATAL: $importerFile is missing — the import app is deployed as a shell\n");
	fwrite(STDERR, "       (controller + routes, no lib/Service). Deploy it before running this.\n");
	$completed = true;
	exit(2);
}
require_once $importerFile;

use OCA\SovereignKanbanImport\Service\DeckImporter;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;

$OWNER = 'Test 1';
$OTHER = 'Test 2';
$MINE = 'zzz-e2e-deck-mien';
$THEIRS = 'zzz-e2e-deck-autrui';
$ARCHIVED_BOARD = 'zzz-e2e-deck-archive';

$db = \OC::$server->get(\OCP\IDBConnection::class);
$rootFolder = \OC::$server->get(\OCP\Files\IRootFolder::class);
$userManager = \OC::$server->get(\OCP\IUserManager::class);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void {
	global $pass, $fail;
	if ($ok) {
		$pass++;
		printf("\e[32m✅\e[0m %-50s %s\n", $label, $detail);
	} else {
		$fail++;
		printf("\e[31m❌\e[0m %-50s %s\n", $label, $detail);
	}
}

foreach ([$OWNER, $OTHER] as $uid) {
	if ($userManager->get($uid) === null) {
		fwrite(STDERR, "FATAL: user '$uid' not found\n");
		exit(2);
	}
}

// --- fixture builders (raw Deck rows — the importer reads these tables) -----

function insertBoard(string $title, string $owner, bool $archived = false): int {
	global $db;
	$q = $db->getQueryBuilder();
	$q->insert('deck_boards')->values([
		'title' => $q->createNamedParameter($title),
		'owner' => $q->createNamedParameter($owner),
		'color' => $q->createNamedParameter('0082c9'),
		'archived' => $q->createNamedParameter($archived ? 1 : 0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'deleted_at' => $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'last_modified' => $q->createNamedParameter(1784200000, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
	]);
	$q->executeStatement();

	return (int) $q->getLastInsertId();
}

function insertStack(string $title, int $boardId, int $order): int {
	global $db;
	$q = $db->getQueryBuilder();
	$q->insert('deck_stacks')->values([
		'title' => $q->createNamedParameter($title),
		'board_id' => $q->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'order' => $q->createNamedParameter($order, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'deleted_at' => $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'last_modified' => $q->createNamedParameter(1784200000, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
	]);
	$q->executeStatement();

	return (int) $q->getLastInsertId();
}

/**
 * @param array{duedate?: ?string, startdate?: ?string, archived?: bool, deleted?: bool, description?: string} $opts
 */
function insertCard(string $title, int $stackId, string $owner, int $order, array $opts = []): int {
	global $db;
	$q = $db->getQueryBuilder();
	$int = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT;
	$q->insert('deck_cards')->values([
		'title' => $q->createNamedParameter($title),
		'description' => $q->createNamedParameter($opts['description'] ?? ''),
		'stack_id' => $q->createNamedParameter($stackId, $int),
		'type' => $q->createNamedParameter('plain'),
		'created_at' => $q->createNamedParameter(1780653600, $int),   // 2026-06-05T10:00:00Z
		'last_modified' => $q->createNamedParameter(1784200000, $int),
		'owner' => $q->createNamedParameter($owner),
		'order' => $q->createNamedParameter($order, $int),
		'archived' => $q->createNamedParameter(!empty($opts['archived']) ? 1 : 0, $int),
		'duedate' => $q->createNamedParameter($opts['duedate'] ?? null),
		'startdate' => $q->createNamedParameter($opts['startdate'] ?? null),
		'deleted_at' => $q->createNamedParameter(!empty($opts['deleted']) ? 1784200000 : 0, $int),
	]);
	$q->executeStatement();

	return (int) $q->getLastInsertId();
}

function insertLabel(string $title, int $boardId): int {
	global $db;
	$q = $db->getQueryBuilder();
	$q->insert('deck_labels')->values([
		'title' => $q->createNamedParameter($title),
		'color' => $q->createNamedParameter('31CC7C'),
		'board_id' => $q->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'last_modified' => $q->createNamedParameter(1784200000, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
	]);
	$q->executeStatement();

	return (int) $q->getLastInsertId();
}

function assignLabel(int $labelId, int $cardId): void {
	global $db;
	$q = $db->getQueryBuilder();
	$int = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT;
	$q->insert('deck_assigned_labels')->values([
		'label_id' => $q->createNamedParameter($labelId, $int),
		'card_id' => $q->createNamedParameter($cardId, $int),
	]);
	$q->executeStatement();
}

function assignUser(string $participant, int $cardId): void {
	global $db;
	$q = $db->getQueryBuilder();
	$int = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT;
	$q->insert('deck_assigned_users')->values([
		'participant' => $q->createNamedParameter($participant),
		'card_id' => $q->createNamedParameter($cardId, $int),
		'type' => $q->createNamedParameter(0, $int),
	]);
	$q->executeStatement();
}

/** Every SK board folder currently in the user's Kanban/, by name. */
function skBoards(string $uid): array {
	$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
	try {
		$kanban = $root->getUserFolder($uid)->get('Kanban');
	} catch (\Throwable $e) {
		return [];
	}
	if (!($kanban instanceof \OCP\Files\Folder)) {
		return [];
	}
	$names = [];
	foreach ($kanban->getDirectoryListing() as $n) {
		if ($n instanceof \OCP\Files\Folder) {
			$names[] = $n->getName();
		}
	}

	return $names;
}

/** Read every card.md under an SK board, keyed by title. */
function skCards(string $uid, string $board): array {
	$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
	$out = [];
	try {
		$dir = $root->getUserFolder($uid)->get('Kanban/' . $board);
	} catch (\Throwable $e) {
		return [];
	}
	$walk = function (\OCP\Files\Folder $f, string $column) use (&$walk, &$out) {
		foreach ($f->getDirectoryListing() as $n) {
			if ($n instanceof \OCP\Files\Folder) {
				$walk($n, $column === '' ? $n->getName() : $column);
				continue;
			}
			if ($n->getName() === 'card.md') {
				try {
					$c = Card::fromMarkdown($n->getContent());
					$out[$c->title] = ['card' => $c, 'column' => $column, 'dir' => $n->getParent()->getName()];
				} catch (\Throwable $e) {
					$out['PARSE-ERREUR-' . $n->getPath()] = ['card' => null, 'column' => $column, 'dir' => ''];
				}
			}
		}
	};
	if ($dir instanceof \OCP\Files\Folder) {
		$walk($dir, '');
	}

	return $out;
}

// --- fixture ---------------------------------------------------------------

$boardIds = [];
$before = [];

try {
	\OC_Util::setupFS($OWNER);
	$before = skBoards($OWNER);

	// A board the target user owns — the one that must come across.
	$mine = insertBoard($MINE, $OWNER);
	$boardIds[] = $mine;
	$todo = insertStack('À faire', $mine, 0);
	$doing = insertStack('En cours', $mine, 1);

	$c1 = insertCard('Corriger: le bug du partage', $todo, $OWNER, 0, [
		'duedate' => '2026-07-20 14:30:00',
		'startdate' => '2026-07-18 09:15:00',
		'description' => "Le corps de la carte.\n\n---\n\nAprès la ligne.",
	]);
	$l1 = insertLabel('urgent', $mine);
	$l2 = insertLabel('infra', $mine);
	assignLabel($l1, $c1);
	assignLabel($l2, $c1);
	assignUser($OWNER, $c1);

	insertCard('Carte sans échéance', $doing, $OWNER, 0);
	insertCard('Carte archivée', $todo, $OWNER, 1, ['archived' => true]);
	insertCard('Carte supprimée', $todo, $OWNER, 2, ['deleted' => true]);

	// A board someone ELSE owns — must not be imported into $OWNER's folder.
	$theirs = insertBoard($THEIRS, $OTHER);
	$boardIds[] = $theirs;
	$theirStack = insertStack('À faire', $theirs, 0);
	insertCard('Ne doit pas être importée', $theirStack, $OTHER, 0);

	// An archived board of the target user — must not be imported.
	$arch = insertBoard($ARCHIVED_BOARD, $OWNER, true);
	$boardIds[] = $arch;
	$archStack = insertStack('À faire', $arch, 0);
	insertCard('Carte d\'un tableau archivé', $archStack, $OWNER, 0);

	check('fixture: 3 tableaux Deck + 6 cartes créés', count($boardIds) === 3);

	// --- run the importer ---------------------------------------------------

	$importer = new DeckImporter($db, $userManager, $rootFolder);
	$ran = false;
	$err = '';
	try {
		$result = $importer->import($OWNER);
		$ran = true;
	} catch (\Throwable $e) {
		$err = get_class($e) . ': ' . str_replace("\n", ' ', $e->getMessage());
	}
	check('[0] l\'import s\'exécute', $ran, $ran ? '' : substr($err, 0, 90));

	$after = skBoards($OWNER);
	$new = array_values(array_diff($after, $before));

	// --- the contract -------------------------------------------------------

	check('[1] le tableau de l\'usager est importé', in_array($MINE, $new, true), 'nouveaux: ' . implode(', ', $new));
	check('[2] le tableau d\'AUTRUI n\'est PAS importé', !in_array($THEIRS, $new, true), 'portée: owner seulement');
	check('[3] le tableau ARCHIVÉ n\'est PAS importé', !in_array($ARCHIVED_BOARD, $new, true));

	$cards = skCards($OWNER, $MINE);

	check('[4] la carte au titre avec « : » est là', isset($cards['Corriger: le bug du partage']), 'titres: ' . implode(' | ', array_keys($cards)));
	check('[5] la carte sans échéance est là', isset($cards['Carte sans échéance']));
	check('[6] la carte ARCHIVÉE n\'est pas importée', !isset($cards['Carte archivée']));
	check('[7] la carte SUPPRIMÉE n\'est pas importée', !isset($cards['Carte supprimée']));

	$c = $cards['Corriger: le bug du partage']['card'] ?? null;
	check('[8] l\'ÉCHÉANCE garde son heure', $c !== null && $c->due_date === '2026-07-20T14:30', $c ? var_export($c->due_date, true) : '—');
	check('[9] la date de DÉBUT garde son heure', $c !== null && $c->start_date === '2026-07-18T09:15', $c ? var_export($c->start_date, true) : '—');
	check('[10] les ÉTIQUETTES Deck deviennent des tags', $c !== null && $c->tags === ['urgent', 'infra'], $c ? json_encode($c->tags, JSON_UNESCAPED_UNICODE) : '—');
	check('[11] l\'ASSIGNÉ est porté', $c !== null && $c->assignees === [$OWNER], $c ? json_encode($c->assignees, JSON_UNESCAPED_UNICODE) : '—');
	check('[12] la DESCRIPTION devient le corps', $c !== null && $c->description === "Le corps de la carte.\n\n---\n\nAprès la ligne.");
	check('[13] created_at (timestamp Deck) est porté', $c !== null && $c->created_at->getTimestamp() === 1780653600, $c ? $c->created_at->format('c') : '—');
	check(
		'[14] l\'id est un UUID, pas l\'id numérique Deck',
		$c !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $c->id) === 1,
		$c ? $c->id : '—',
	);
	check(
		'[15] la carte est dans la colonne de son stack Deck',
		isset($cards['Corriger: le bug du partage']) && str_contains($cards['Corriger: le bug du partage']['column'], 'À faire'),
		$cards['Corriger: le bug du partage']['column'] ?? '—',
	);
	check(
		'[16] « Carte sans échéance » est dans « En cours »',
		isset($cards['Carte sans échéance']) && str_contains($cards['Carte sans échéance']['column'], 'En cours'),
		$cards['Carte sans échéance']['column'] ?? '—',
	);
} finally {
	// --- teardown: the fixture rows, then anything the import created --------
	$removed = 0;
	foreach ($boardIds as $bid) {
		$stacks = $db->executeQuery('SELECT id FROM oc_deck_stacks WHERE board_id = ' . (int) $bid)->fetchAll();
		foreach ($stacks as $s) {
			$cardRows = $db->executeQuery('SELECT id FROM oc_deck_cards WHERE stack_id = ' . (int) $s['id'])->fetchAll();
			foreach ($cardRows as $cr) {
				$db->executeStatement('DELETE FROM oc_deck_assigned_labels WHERE card_id = ' . (int) $cr['id']);
				$db->executeStatement('DELETE FROM oc_deck_assigned_users WHERE card_id = ' . (int) $cr['id']);
			}
			$db->executeStatement('DELETE FROM oc_deck_cards WHERE stack_id = ' . (int) $s['id']);
		}
		$db->executeStatement('DELETE FROM oc_deck_stacks WHERE board_id = ' . (int) $bid);
		$db->executeStatement('DELETE FROM oc_deck_labels WHERE board_id = ' . (int) $bid);
		$db->executeStatement('DELETE FROM oc_deck_boards WHERE id = ' . (int) $bid);
		$removed++;
	}
	printf("\nlignes Deck de test retirées : %d tableau(x)\n", $removed);

	// Only what appeared during this run — never a pre-existing board.
	$appeared = array_values(array_diff(skBoards($OWNER), $before));
	foreach ($appeared as $name) {
		try {
			$rootFolder->getUserFolder($OWNER)->get('Kanban/' . $name)->delete();
		} catch (\Throwable $e) {
			// nothing to do
		}
	}
	$left = array_values(array_diff(skBoards($OWNER), $before));
	printf("tableaux SK créés par l'import et retirés : %s %s\n",
		count($appeared) . ' (' . implode(', ', $appeared) . ')',
		$left === [] ? "\e[32m✅\e[0m" : "\e[31m❌ restent: " . implode(', ', $left) . "\e[0m");
}

printf("\n%d passed, %d failed\n", $pass, $fail);
$completed = true;
exit($fail === 0 ? 0 : 1);
