<?php

/**
 * @file
 * Functional (e2e) test: a board's archived state (Alain, 2026-07-19) round-trips
 * through .board.yml and index() exposes it so the sidebar can split boards into
 * « Tous » / « Tableaux archivés ».
 *
 * FALSIFICATION: drop the `archived` key from Board::toYaml() (or the read in
 * loadFromYml) → [roundtrip] goes red.
 *
 * Usage: runuser -u www-data -- php /tmp/board_archive.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: one throwaway board zzz-e2e-board-archive under the test account.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ReceivedBoardLocator;

$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — this test died before finishing. NOT a pass.\n");
		exit(70);
	}
});

$OWNER = 'Test 1';
$BOARD = 'zzz-e2e-board-archive';

$server = \OC::$server;
$userSession = $server->get(\OCP\IUserSession::class);
$rootFolder = $server->get(\OCP\Files\IRootFolder::class);
$shareManager = $server->get(\OCP\Share\IManager::class);
$request = $server->get(\OCP\IRequest::class);

$gateway = new NextcloudShareGateway($shareManager, $rootFolder, $userSession);
$shareService = new BoardShareService($gateway);
$receivedLocator = new ReceivedBoardLocator($shareManager, $userSession);
$boardCtrl = new BoardController($request, $userSession, $rootFolder, $shareService, $receivedLocator);

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

function check(string $label, bool $ok, string $detail = ''): void {
	global $pass, $fail;
	if ($ok) {
		$pass++;
		printf("\e[32m✅\e[0m %-52s %s\n", $label, $detail);
	} else {
		$fail++;
		printf("\e[31m❌\e[0m %-52s %s\n", $label, $detail);
	}
}

function findBoard(BoardController $ctrl, string $id): ?array {
	foreach ($ctrl->index()->getData()['boards'] as $b) {
		if ($b['id'] === $id) {
			return $b;
		}
	}
	return null;
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

	$b0 = findBoard($boardCtrl, $boardId);
	check('[new-board-active] a new board is not archived', array_key_exists('archived', $b0) && $b0['archived'] === null, var_export($b0['archived'] ?? 'KEY-ABSENT', true));

	$instant = '2026-07-19T13:00:00Z';
	$res = $boardCtrl->update($boardId, null, null, null, $instant);
	check('[archive] archiving returns 200', $res->getStatus() === 200, 'status ' . $res->getStatus());
	check('[roundtrip] index() reports the board archived', (findBoard($boardCtrl, $boardId)['archived'] ?? null) === $instant, var_export(findBoard($boardCtrl, $boardId)['archived'] ?? null, true));

	// name edit must not lose the archived state (carry-through through withName).
	$boardCtrl->update($boardId, 'Renommé archivé');
	check('[archived-survives-rename] archived survives a name edit', (findBoard($boardCtrl, $boardId)['archived'] ?? null) === $instant, var_export(findBoard($boardCtrl, $boardId)['archived'] ?? null, true));

	// unarchive with ''.
	$boardCtrl->update($boardId, null, null, null, '');
	$bU = findBoard($boardCtrl, $boardId);
	check('[unarchive] empty string unarchives', array_key_exists('archived', $bU) && $bU['archived'] === null, var_export($bU['archived'] ?? 'KEY-ABSENT', true));

	printf("\n%d passed, %d failed\n", $pass, $fail);
} finally {
	teardown($OWNER, $BOARD);
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
