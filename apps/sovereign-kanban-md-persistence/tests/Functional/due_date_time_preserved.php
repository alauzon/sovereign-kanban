<?php

/**
 * @file
 * Functional (e2e) test: a due date's TIME survives the real write path.
 *
 * WHY THIS EXISTS, and it is the whole point. On 2026-07-15 the card.md format
 * was fixed to keep the time on due_date, and a 13-test conformance suite went
 * green. A real write through FileCardRepository on a booted Nextcloud passed
 * 15/15. The docs were updated to say the time is preserved.
 *
 * Then Alain opened a browser and set 14:30. It stored 00:00.
 *
 * The truncation lived in a SECOND place — CardController did its own
 * substr($due_date, 0, 10) — and every layer that was tested sat below it. The
 * unit tier cannot reach the controller (it needs OCP), so there was no test at
 * the layer where the bug actually was. A green suite plus a green real-write
 * script proved the organ and said nothing about the organism.
 *
 * This test drives the ACTUAL CardController, which is the only thing the
 * browser ever talks to. It is the layer that was missing.
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/due_date_time_preserved.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: creates a throwaway board prefixed "zzz-e2e-due-" under the test
 * account only, and deletes it unconditionally. Touches no real card. Tshinanu
 * (CT 211) hosts real community members — see share_ownership_enforcement.php.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Service\MarkdownRenderer;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ReceivedBoardLocator;
use OCP\AppFramework\Http\DataResponse;

// --- abnormal-termination guard — DO NOT REMOVE ----------------------------
// Once OC_Util::setupFS() has run, an UNCAUGHT exception is swallowed by
// Nextcloud's handler and this script exits 0 — reporting SUCCESS while dying
// halfway. It also skips the teardown, leaving throwaway boards behind.
$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — this test died before finishing. NOT a pass.\n");
		fwrite(STDERR, "   Teardown did NOT run: check for a leftover zzz-e2e-due-* board.\n");
		exit(70);
	}
});

$OWNER = 'Test 1';
$BOARD = 'zzz-e2e-due';

$server = \OC::$server;
$userSession = $server->get(\OCP\IUserSession::class);
$userManager = $server->get(\OCP\IUserManager::class);
$rootFolder = $server->get(\OCP\Files\IRootFolder::class);
$shareManager = $server->get(\OCP\Share\IManager::class);
$request = $server->get(\OCP\IRequest::class);

$gateway = new NextcloudShareGateway($shareManager, $rootFolder, $userSession);
$shareService = new BoardShareService($gateway);
$receivedLocator = new ReceivedBoardLocator($shareManager, $userSession);
$boardCtrl = new BoardController($request, $userSession, $rootFolder, $shareService, $receivedLocator);
// Resolved from the DI container, NOT constructed by hand: a manual `new`
// pins the constructor signature, and adding a dependency to CardController
// (IUserManager, 2026-07-17) killed this test with a silent-then-guarded
// exit 70. The container always wires the current signature.
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

/** Raw bytes of the card.md the app actually wrote — the record itself. */
function rawCardFile(string $uid, string $board, string $cardId): ?string {
	$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
	$dir = $root->getUserFolder($uid)->get('Kanban/' . $board);
	foreach ($dir->getDirectoryListing() as $col) {
		if (!($col instanceof \OCP\Files\Folder)) {
			continue;
		}
		foreach ($col->getDirectoryListing() as $cardDir) {
			if ($cardDir instanceof \OCP\Files\Folder && str_starts_with($cardDir->getName(), substr($cardId, 0, 8))) {
				return $cardDir->get('card.md')->getContent();
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
	// --- setup: a throwaway board with one card ----------------------------
	$res = $boardCtrl->create($BOARD, '#0082c9');
	if (!($res instanceof DataResponse) || $res->getStatus() >= 300) {
		fwrite(STDERR, "FATAL: could not create the board (status " . $res->getStatus() . ")\n");
		$completed = true;
		exit(1);
	}
	$boardId = $res->getData()['board']['id'] ?? $BOARD;

	$res = $cardCtrl->create($boardId, 'Réunion du conseil', 'Backlog');
	check('setup: card created', $res->getStatus() === 201, 'status ' . $res->getStatus());
	$cardId = $res->getData()['card']['id'];

	// --- [1] THE BUG Alain found in the browser ----------------------------
	// The editor sends exactly this string from <input type="datetime-local">.
	$res = $cardCtrl->update($boardId, $cardId, due_date: '2026-07-20T14:30');
	check(
		'[1] the API answers with the time kept',
		($res->getData()['card']['due_date'] ?? null) === '2026-07-20T14:30',
		'got ' . var_export($res->getData()['card']['due_date'] ?? null, true),
	);

	// The API response is not the record. The FILE is.
	$raw = rawCardFile($OWNER, $BOARD, $cardId);
	check(
		'[1] the FILE on disk keeps the time',
		$raw !== null && str_contains($raw, '2026-07-20T14:30'),
		$raw !== null && preg_match('/due_date: .*/', $raw, $m) ? trim($m[0]) : 'no due_date line',
	);

	// Reloading is what the browser does next — this is what Alain saw as 00:00.
	$res = $cardCtrl->show($boardId, $cardId);
	check(
		'[1] reloading the card still has the time',
		($res->getData()['card']['due_date'] ?? null) === '2026-07-20T14:30',
		'got ' . var_export($res->getData()['card']['due_date'] ?? null, true),
	);

	// --- [2] start_date has the identical defect ---------------------------
	$res = $cardCtrl->update($boardId, $cardId, start_date: '2026-07-18T09:15');
	check(
		'[2] start_date keeps its time too',
		($res->getData()['card']['start_date'] ?? null) === '2026-07-18T09:15',
		'got ' . var_export($res->getData()['card']['start_date'] ?? null, true),
	);

	// --- [3] a date with no time must not be given one ---------------------
	$res = $cardCtrl->update($boardId, $cardId, due_date: '2026-08-01');
	check(
		'[3] a date without a time stays without one',
		($res->getData()['card']['due_date'] ?? null) === '2026-08-01',
		'got ' . var_export($res->getData()['card']['due_date'] ?? null, true),
	);

	// --- [4] clearing must still clear -------------------------------------
	// Not written with ?? on purpose: a cleared date IS null, and `null ?? 'x'`
	// yields 'x', so ?? cannot tell "absent" from "explicitly null".
	$res = $cardCtrl->update($boardId, $cardId, due_date: '');
	$data = $res->getData()['card'];
	check(
		'[4] an empty value still clears the due date',
		array_key_exists('due_date', $data) && $data['due_date'] === null,
		array_key_exists('due_date', $data) ? 'got ' . var_export($data['due_date'], true) : 'key absent',
	);

	// --- [5] an untouched card keeps its date ------------------------------
	$cardCtrl->update($boardId, $cardId, due_date: '2026-09-09T16:45');
	$res = $cardCtrl->update($boardId, $cardId, title: 'Titre changé');
	check(
		'[5] editing the title does not disturb the due date',
		($res->getData()['card']['due_date'] ?? null) === '2026-09-09T16:45',
		'got ' . var_export($res->getData()['card']['due_date'] ?? null, true),
	);
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
