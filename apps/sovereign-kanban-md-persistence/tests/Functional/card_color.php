<?php

/**
 * @file
 * Functional (e2e) test: a card's colour (Alain, 2026-07-19) round-trips through
 * card.md, is cleared by '', and survives an unrelated edit (carry-through).
 *
 * FALSIFICATION: remove `color: $newColor` from the update() reconstruction in
 * CardController → [color-survives-edit] goes red (every edit erases the colour).
 *
 * Usage: runuser -u www-data -- php /tmp/card_color.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: one throwaway board zzz-e2e-color under the test account.
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
$BOARD = 'zzz-e2e-color';

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
	$cardId = $cardCtrl->create($boardId, 'Carte colorée', 'Backlog')->getData()['card']['id'];

	check('[new-card-no-color] a new card has no colour', $cardCtrl->show($boardId, $cardId)->getData()['card']['color'] === null, '');

	// Set a colour.
	$res = $cardCtrl->update($boardId, $cardId, color: '#e9322d');
	check('[set] setting a colour returns 200', $res->getStatus() === 200, 'status ' . $res->getStatus());
	check('[set-response] response carries the colour', ($res->getData()['card']['color'] ?? null) === '#e9322d', var_export($res->getData()['card']['color'] ?? null, true));
	$reloaded = Card::fromMarkdown(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent());
	check('[roundtrip] colour round-trips through the file', $reloaded->color === '#e9322d', var_export($reloaded->color, true));

	// Survives an unrelated edit (falsifies the field-drop bug).
	$cardCtrl->update($boardId, $cardId, priority: '1');
	$after = Card::fromMarkdown(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent());
	check('[color-survives-edit] colour untouched by an unrelated edit', $after->color === '#e9322d', var_export($after->color, true));

	// Cleared with ''.
	$cardCtrl->update($boardId, $cardId, color: '');
	$cleared = Card::fromMarkdown(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent());
	check('[clear] empty string clears the colour', $cleared->color === null, var_export($cleared->color, true));
	// Match the frontmatter KEY 'color:', not the substring 'color' (the title
	// "Carte colorée" contains it — that bit the first run).
	check('[clear-file] colour key removed from the file', !str_contains(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent(), 'color:'), '');

	// Omitting colour leaves it unchanged.
	$cardCtrl->update($boardId, $cardId, color: '#0082c9');
	$cardCtrl->update($boardId, $cardId, title: 'Renommée'); // color defaults null → unchanged
	$still = Card::fromMarkdown(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent());
	check('[omit-leaves] omitting colour leaves it unchanged', $still->color === '#0082c9', var_export($still->color, true));

	printf("\n%d passed, %d failed\n", $pass, $fail);
} finally {
	teardown($OWNER, $BOARD);
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
