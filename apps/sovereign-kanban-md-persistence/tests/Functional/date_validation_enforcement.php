<?php

/**
 * @file
 * Functional (e2e) test: a malformed date must be REFUSED, not stored.
 *
 * Found 2026-07-18 by Steve, on Chrome/Windows. A <input type="datetime-local">
 * accepts a SIX-digit year when you type into it by hand ("2026" then "07" in
 * the year field → "202607"). The browser is within its rights. The bug is ours:
 * normalizeDate's regex wants a 4-digit year, "202607-07-19" does not match, and
 * the fallback stored it VERBATIM. Two cards on Tshinanu ended up with
 * start_date '202607-07-19T14:08' and due_date '202609-07-21T12:52' on disk.
 *
 * Same shape as the D5 assignee hole: I normalized permissively where I should
 * have validated strictly. Normalizing is not validating. So the server now
 * refuses a date it cannot parse as a real calendar date — 400, nothing written
 * — exactly as it refuses a non-existent assignee.
 *
 * Reading stays tolerant on purpose: a card already carrying a corrupt date must
 * still load (so it can be seen and fixed), so the strict check lives in the
 * controller (write path), not in normalizeDate (read path).
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/date_validation_enforcement.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: one throwaway board zzz-e2e-date under the test account, deleted
 * unconditionally. Touches no real card.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — this test died before finishing. NOT a pass.\n");
		fwrite(STDERR, "   Teardown may NOT have run: check for a leftover zzz-e2e-date board.\n");
		exit(70);
	}
});

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ReceivedBoardLocator;
use OCP\AppFramework\Http\DataResponse;

$OWNER = 'Test 1';
$BOARD = 'zzz-e2e-date';

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
		printf("\e[32m✅\e[0m %-56s %s\n", $label, $detail);
	} else {
		$fail++;
		printf("\e[31m❌\e[0m %-56s %s\n", $label, $detail);
	}
}

function storedDate(string $uid, string $board, string $cardId, string $field): ?string {
	$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
	$dir = $root->getUserFolder($uid)->get('Kanban/' . $board);
	foreach ($dir->getDirectoryListing() as $col) {
		if (!($col instanceof \OCP\Files\Folder)) {
			continue;
		}
		foreach ($col->getDirectoryListing() as $c) {
			if ($c instanceof \OCP\Files\Folder && str_starts_with($c->getName(), substr($cardId, 0, 8))) {
				$card = Card::fromMarkdown($c->get('card.md')->getContent());
				return $field === 'due' ? $card->due_date : $card->start_date;
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
	$res = $boardCtrl->create($BOARD, '#0082c9');
	if (!($res instanceof DataResponse) || $res->getStatus() >= 300) {
		fwrite(STDERR, "FATAL: could not create board (status " . $res->getStatus() . ")\n");
		$completed = true;
		exit(1);
	}
	$boardId = $res->getData()['board']['id'] ?? $BOARD;

	$res = $cardCtrl->create($boardId, 'Carte à dater', 'Backlog');
	check('setup: card created', $res->getStatus() === 201, 'status ' . $res->getStatus());
	$cardId = $res->getData()['card']['id'];

	// --- [1] THE bug: a six-digit year, exactly what Steve produced ---------
	$res = $cardCtrl->update($boardId, $cardId, due_date: '202609-07-21T12:52');
	check(
		'[1] a 6-digit year is refused (400 invalid_date)',
		$res->getStatus() === 400 && ($res->getData()['error'] ?? '') === 'invalid_date',
		'status ' . $res->getStatus() . ' error=' . var_export($res->getData()['error'] ?? null, true),
	);
	check(
		'[1] and NOTHING corrupt was written',
		storedDate($OWNER, $BOARD, $cardId, 'due') === null,
		var_export(storedDate($OWNER, $BOARD, $cardId, 'due'), true),
	);

	// --- [2] start_date, same defect ----------------------------------------
	$res = $cardCtrl->update($boardId, $cardId, start_date: '202607-07-19T14:08');
	check('[2] start_date with a 6-digit year is refused', $res->getStatus() === 400, 'status ' . $res->getStatus());

	// --- [3] an impossible calendar date is refused -------------------------
	$res = $cardCtrl->update($boardId, $cardId, due_date: '2026-02-30');
	check('[3] Feb 30 is refused', $res->getStatus() === 400, 'status ' . $res->getStatus());

	// --- [4] garbage is refused ---------------------------------------------
	$res = $cardCtrl->update($boardId, $cardId, due_date: 'pas une date');
	check('[4] free text is refused', $res->getStatus() === 400, 'status ' . $res->getStatus());

	// --- [5] a valid date-time is accepted ----------------------------------
	$res = $cardCtrl->update($boardId, $cardId, due_date: '2026-07-20T14:30');
	check(
		'[5] a valid date-time is accepted',
		$res->getStatus() === 200 && ($res->getData()['card']['due_date'] ?? null) === '2026-07-20T14:30',
		'status ' . $res->getStatus() . ' → ' . var_export($res->getData()['card']['due_date'] ?? null, true),
	);

	// --- [6] a valid plain date is accepted ---------------------------------
	$res = $cardCtrl->update($boardId, $cardId, due_date: '2026-08-01');
	check('[6] a valid plain date is accepted', $res->getStatus() === 200 && ($res->getData()['card']['due_date'] ?? null) === '2026-08-01', 'status ' . $res->getStatus());

	// --- [7] clearing still works -------------------------------------------
	// array_key_exists, not ??: a cleared date IS null, and `null ?? 'x'` is 'x'.
	$res = $cardCtrl->update($boardId, $cardId, due_date: '');
	$d = $res->getData()['card'];
	check('[7] clearing still works', $res->getStatus() === 200 && array_key_exists('due_date', $d) && $d['due_date'] === null, 'status ' . $res->getStatus());
} finally {
	teardown($OWNER, $BOARD);
	$gone = false;
	try {
		$rootFolder->getUserFolder($OWNER)->get('Kanban/' . $BOARD);
	} catch (\Throwable $e) {
		$gone = true;
	}
	printf("\nthrowaway board removed: %s\n", $gone ? "\e[32m✅\e[0m" : "\e[31m❌ STILL THERE\e[0m");
}

printf("\n%d passed, %d failed\n", $pass, $fail);
$completed = true;
exit($fail === 0 ? 0 : 1);
