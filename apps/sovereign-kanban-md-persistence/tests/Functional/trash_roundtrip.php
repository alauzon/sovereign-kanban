<?php

/**
 * @file
 * Functional (e2e) test: the Corbeille (Alain, 2026-07-19). Deleting a card is
 * SOFT — it moves to .trash/ and is listed in the trash, restorable to a column,
 * or purged permanently. A trashed card never shows on the board.
 *
 * FALSIFICATION: make destroy() hard-delete again (deleteById) → [in-trash] and
 * [restore-back] go red (the card is gone, not recoverable).
 *
 * Usage: runuser -u www-data -- php /tmp/trash_roundtrip.php
 * Exit codes: 0 passed · 1 failed · 70 died · 2 no account.
 *
 * SAFETY: one throwaway board zzz-e2e-trash under the test account.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ReceivedBoardLocator;

$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — NOT a pass.\n");
		exit(70);
	}
});

$OWNER = 'Test 1';
$BOARD = 'zzz-e2e-trash';

$server = \OC::$server;
$userSession = $server->get(\OCP\IUserSession::class);
$rootFolder = $server->get(\OCP\Files\IRootFolder::class);
$shareManager = $server->get(\OCP\Share\IManager::class);
$request = $server->get(\OCP\IRequest::class);
$gateway = new NextcloudShareGateway($shareManager, $rootFolder, $userSession);
$shareService = new BoardShareService($gateway);
$receivedLocator = new ReceivedBoardLocator($shareManager, $userSession);
$boardCtrl = new BoardController($request, $userSession, $rootFolder, $shareService, $receivedLocator);
$cardCtrl = $server->get(CardController::class);

$pass = 0;
$fail = 0;

function actAs(string $uid): void {
	$u = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
	if ($u === null) {
		fwrite(STDERR, "FATAL: user '$uid' not found\n");
		exit(2);
	}
	\OC::$server->get(\OCP\IUserSession::class)->setUser($u);
	\OC_Util::setupFS($uid);
}

function check(string $l, bool $ok, string $d = ''): void {
	global $pass, $fail;
	$pass += $ok ? 1 : 0;
	$fail += $ok ? 0 : 1;
	printf("%s %-50s %s\n", $ok ? "\e[32m✅\e[0m" : "\e[31m❌\e[0m", $l, $d);
}

/** All card titles currently on the board (across columns). */
function boardTitles(CardController $c, string $boardId): array {
	$out = [];
	foreach ($c->index($boardId)->getData()['cards'] as $col => $cards) {
		foreach ($cards as $card) {
			$out[] = $card['title'];
		}
	}
	return $out;
}

function trashTitles(CardController $c, string $boardId): array {
	return array_map(static fn (array $t): string => $t['title'], $c->trash($boardId)->getData()['trash']);
}

function teardown(string $uid, string $board): void {
	try {
		\OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->get('Kanban/' . $board)->delete();
	} catch (\Throwable $e) {
		// already gone
	}
}

actAs($OWNER);
teardown($OWNER, $BOARD);

try {
	$boardId = $boardCtrl->create($BOARD, '#0082c9')->getData()['board']['id'] ?? $BOARD;
	$cardId = $cardCtrl->create($boardId, 'Carte jetable', 'Backlog')->getData()['card']['id'];

	// --- soft delete → not on board, in trash ------------------------------
	$cardCtrl->destroy($boardId, $cardId);
	check('[off-board] a deleted card leaves the board', !in_array('Carte jetable', boardTitles($cardCtrl, $boardId), true), json_encode(boardTitles($cardCtrl, $boardId)));
	check('[in-trash] and lands in the Corbeille', in_array('Carte jetable', trashTitles($cardCtrl, $boardId), true), json_encode(trashTitles($cardCtrl, $boardId)));
	// The .trash column must NOT appear as a board column.
	check('[no-trash-column] .trash is not shown as a column', !array_key_exists('.trash', $cardCtrl->index($boardId)->getData()['cards']), json_encode(array_keys($cardCtrl->index($boardId)->getData()['cards'])));

	// --- restore → back on board, gone from trash --------------------------
	$res = $cardCtrl->restore($boardId, $cardId, 'Backlog');
	check('[restore-ok] restoring returns 200', $res->getStatus() === 200, 'status ' . $res->getStatus());
	check('[restore-back] the card is back on the board', in_array('Carte jetable', boardTitles($cardCtrl, $boardId), true), json_encode(boardTitles($cardCtrl, $boardId)));
	check('[restore-empties] and gone from the Corbeille', !in_array('Carte jetable', trashTitles($cardCtrl, $boardId), true), json_encode(trashTitles($cardCtrl, $boardId)));

	// --- delete again, then purge → gone for good --------------------------
	$cardCtrl->destroy($boardId, $cardId);
	check('[re-trash] deleting again puts it back in the Corbeille', in_array('Carte jetable', trashTitles($cardCtrl, $boardId), true), '');
	$cardCtrl->purge($boardId, $cardId);
	check('[purge] purging empties the Corbeille', !in_array('Carte jetable', trashTitles($cardCtrl, $boardId), true), json_encode(trashTitles($cardCtrl, $boardId)));
	check('[purge-gone] and it is not on the board either', !in_array('Carte jetable', boardTitles($cardCtrl, $boardId), true), '');

	printf("\n%d passed, %d failed\n", $pass, $fail);
} finally {
	teardown($OWNER, $BOARD);
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
