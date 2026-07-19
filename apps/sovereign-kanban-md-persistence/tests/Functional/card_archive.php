<?php

/**
 * @file
 * Functional (e2e) test: a card's archived state (Alain, 2026-07-19) round-trips
 * through card.md, clears with '', and survives an unrelated edit (carry-through).
 *
 * FALSIFICATION: remove `archived: $newArchived` from the update() reconstruction
 * in CardController → [archived-survives-edit] goes red.
 *
 * Usage: runuser -u www-data -- php /tmp/card_archive.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: one throwaway board zzz-e2e-archive under the test account.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
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
$BOARD = 'zzz-e2e-archive';

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

function cardDir(string $uid, string $board, string $cardId): ?\OCP\Files\Folder {
	$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
	$dir = $root->getUserFolder($uid)->get('Kanban/' . $board);
	foreach ($dir->getDirectoryListing() as $col) {
		if (!($col instanceof \OCP\Files\Folder)) {
			continue;
		}
		foreach ($col->getDirectoryListing() as $c) {
			if ($c instanceof \OCP\Files\Folder && str_starts_with($c->getName(), substr($cardId, 0, 8))) {
				return $c;
			}
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
	$cardId = $cardCtrl->create($boardId, 'Carte archivable', 'Backlog')->getData()['card']['id'];

	check('[new-card-active] a new card is not archived', $cardCtrl->show($boardId, $cardId)->getData()['card']['archived'] === null, '');

	$instant = '2026-07-19T12:00:00Z';
	$res = $cardCtrl->update($boardId, $cardId, archived: $instant);
	check('[archive] archiving returns 200', $res->getStatus() === 200, 'status ' . $res->getStatus());
	check('[archive-response] response carries archived', ($res->getData()['card']['archived'] ?? null) === $instant, var_export($res->getData()['card']['archived'] ?? null, true));
	$reloaded = Card::fromMarkdown(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent());
	check('[roundtrip] archived round-trips through the file', $reloaded->archived === $instant, var_export($reloaded->archived, true));

	// Survives an unrelated edit (falsifies the field-drop bug).
	$cardCtrl->update($boardId, $cardId, priority: '1');
	$after = Card::fromMarkdown(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent());
	check('[archived-survives-edit] archived untouched by an unrelated edit', $after->archived === $instant, var_export($after->archived, true));

	// Unarchive with ''.
	$cardCtrl->update($boardId, $cardId, archived: '');
	$cleared = Card::fromMarkdown(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent());
	check('[unarchive] empty string unarchives', $cleared->archived === null, var_export($cleared->archived, true));

	printf("\n%d passed, %d failed\n", $pass, $fail);
} finally {
	teardown($OWNER, $BOARD);
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
