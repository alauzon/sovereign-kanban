<?php

/**
 * @file
 * Functional test: the import controller imports for the CURRENT session user
 * only (Alain, 2026-07-19). The userId argument was removed — a caller must not
 * be able to import into someone else's files. Runs as a non-admin (Test 1).
 *
 * Seeds one owned Deck board for Test 1, calls ImportController::import() with
 * Test 1 as the session user, asserts success and that the board landed in
 * Test 1's Kanban/. Cleans up the Deck board and the imported SK board.
 *
 * Usage: runuser -u www-data -- php /tmp/import_controller_scope.php
 * Exit codes: 0 passed · 1 failed · 70 died · 2 no account.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

require_once '/var/www/nextcloud/lib/base.php';

// The import app's autoloader isn't registered in this CLI context — load the
// two classes under test by hand (same as deck_import_contract.php).
require_once '/var/www/nextcloud/apps/sovereign-kanban-import/lib/Service/DeckImporter.php';
require_once '/var/www/nextcloud/apps/sovereign-kanban-import/lib/Controller/ImportController.php';

use OCA\SovereignKanbanImport\Controller\ImportController;
use OCA\SovereignKanbanImport\Service\DeckImporter;

$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — NOT a pass.\n");
		exit(70);
	}
});

$OWNER = 'Test 1';
$BOARD_TITLE = 'zzz-e2e-import-scope';

$db = \OC::$server->get(\OCP\IDBConnection::class);
$um = \OC::$server->get(\OCP\IUserManager::class);
$us = \OC::$server->get(\OCP\IUserSession::class);
$root = \OC::$server->get(\OCP\Files\IRootFolder::class);

$user = $um->get($OWNER);
if ($user === null) {
	fwrite(STDERR, "no account\n");
	exit(2);
}

$pass = 0;
$fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
	global $pass, $fail;
	$pass += $ok ? 1 : 0;
	$fail += $ok ? 0 : 1;
	printf("%s %-50s %s\n", $ok ? "\e[32m✅\e[0m" : "\e[31m❌\e[0m", $l, $d);
}

// --- seed one owned Deck board with a stack + card, via the DB ----------------
function nowStr(): string {
	return '2026-07-19 12:00:00';
}
$deckBoardId = null;
$stackId = null;
try {
	$us->setUser($user);
	\OC_Util::setupFS($OWNER);

	$q = $db->getQueryBuilder();
	$q->insert('deck_boards')->values([
		'title' => $q->createNamedParameter($BOARD_TITLE),
		'owner' => $q->createNamedParameter($OWNER),
		'color' => $q->createNamedParameter('0082c9'),
		'archived' => $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'deleted_at' => $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'last_modified' => $q->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
	]);
	$q->executeStatement();
	$deckBoardId = (int) $db->lastInsertId('deck_boards');

	$q = $db->getQueryBuilder();
	$q->insert('deck_stacks')->values([
		'title' => $q->createNamedParameter('À faire'),
		'board_id' => $q->createNamedParameter($deckBoardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'order' => $q->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'deleted_at' => $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'last_modified' => $q->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
	]);
	$q->executeStatement();
	$stackId = (int) $db->lastInsertId('deck_stacks');

	$q = $db->getQueryBuilder();
	$q->insert('deck_cards')->values([
		'title' => $q->createNamedParameter('Carte importée par le contrôleur'),
		'stack_id' => $q->createNamedParameter($stackId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'type' => $q->createNamedParameter('plain'),
		'owner' => $q->createNamedParameter($OWNER),
		'order' => $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'archived' => $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'deleted_at' => $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'created_at' => $q->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'last_modified' => $q->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
	]);
	$q->executeStatement();

	// --- call the controller as Test 1 (non-admin, session user) -------------
	$importer = new DeckImporter($db, $um, $root);
	$controller = new ImportController(\OC::$server->get(\OCP\IRequest::class), $importer, $us);
	$res = $controller->import();
	$data = $res->getData();
	check('[ok] the controller reports success', ($data['success'] ?? false) === true, json_encode($data['message'] ?? null));
	check('[scoped] it imported at least the seeded board', ($data['boards_imported'] ?? 0) >= 1, 'boards=' . ($data['boards_imported'] ?? 0));

	// The imported board lands in Test 1's Kanban/ under a slug of the title.
	$slug = 'zzz-e2e-import-scope';
	$landed = $root->getUserFolder($OWNER)->nodeExists('Kanban/' . $slug);
	check('[in-my-folder] the board is in the current user\'s Kanban/', $landed, $slug);

	printf("\n%d passed, %d failed\n", $pass, $fail);
} catch (\Throwable $e) {
	fwrite(STDOUT, 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
	$fail++;
} finally {
	// cleanup: Deck rows + imported SK board.
	try {
		if ($deckBoardId !== null) {
			foreach (['deck_cards' => 'stack_id', 'deck_stacks' => 'board_id', 'deck_boards' => 'id'] as $table => $col) {
				$d = $db->getQueryBuilder();
				$val = $table === 'deck_cards' ? $stackId : $deckBoardId;
				if ($val !== null) {
					$d->delete($table)->where($d->expr()->eq($col, $d->createNamedParameter($val, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
					$d->executeStatement();
				}
			}
		}
		$root->getUserFolder($OWNER)->get('Kanban/zzz-e2e-import-scope')->delete();
	} catch (\Throwable $e) {
		// best effort
	}
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
