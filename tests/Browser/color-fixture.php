<?php

/**
 * @file
 * Fixture for vue-cardcolor.spec.js: seeds a board WITH a palette tag and one
 * card, so the card ⋯ menu shows a colour swatch. The palette editor is edit-only
 * in the UI and editing disrupts the board view mid-test, so we seed the palette
 * server-side instead. Run as www-data on CT 211.
 *
 * Usage: php color-fixture.php setup | teardown
 * SAFETY: single board zzz-e2e-cardcolor under the Test 1 account only.
 */

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ReceivedBoardLocator;

$USER = 'Test 1';
$BOARD = 'zzz-e2e-cardcolor';
$action = $argv[1] ?? '';

$u = \OC::$server->get(\OCP\IUserManager::class)->get($USER);
if ($u === null) {
	fwrite(STDERR, "no account\n");
	exit(2);
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($u);
\OC_Util::setupFS($USER);

$rootFolder = \OC::$server->get(\OCP\Files\IRootFolder::class);

function teardown(string $user, string $board): void {
	try {
		\OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($user)->get('Kanban/' . $board)->delete();
	} catch (\Throwable $e) {
		// already gone
	}
}

if ($action === 'teardown') {
	teardown($USER, $BOARD);
	fwrite(STDOUT, "ok\n");
	exit(0);
}

if ($action !== 'setup') {
	fwrite(STDERR, "usage: setup|teardown\n");
	exit(2);
}

teardown($USER, $BOARD);

$server = \OC::$server;
$userSession = $server->get(\OCP\IUserSession::class);
$shareManager = $server->get(\OCP\Share\IManager::class);
$request = $server->get(\OCP\IRequest::class);
$gateway = new NextcloudShareGateway($shareManager, $rootFolder, $userSession);
$shareService = new BoardShareService($gateway);
$receivedLocator = new ReceivedBoardLocator($shareManager, $userSession);
$boardCtrl = new BoardController($request, $userSession, $rootFolder, $shareService, $receivedLocator);
$cardCtrl = $server->get(CardController::class);

$boardId = $boardCtrl->create($BOARD, '#0082c9')->getData()['board']['id'] ?? $BOARD;
$boardCtrl->update($boardId, null, null, [['name' => 'Urgent', 'color' => '#e9322d']]);
$cardCtrl->create($boardId, 'Carte à colorer', 'Backlog');

fwrite(STDOUT, "ok\n");
