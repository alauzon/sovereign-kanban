<?php

/**
 * @file
 * Functional (e2e) test: read-only board shares must refuse card writes.
 *
 * Runs INSIDE a real Nextcloud (the unit suite can't — the controllers depend
 * on OCP, which only exists in a booted NC). It boots NC, drives the actual
 * BoardController / CardController against real Nextcloud shares between two
 * accounts, and asserts the read-only bypass (fixed 2026-07-12) stays closed.
 *
 * It is the reproduce-then-fix regression guard for that fix at the layer the
 * PHPUnit suite cannot reach: run it on staging BEFORE and AFTER any change to
 * the sharing / write path.
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/readonly_enforcement.php
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 *
 * Accounts: 'Test 1' (owner) and 'Test 2' (recipient) — pre-provisioned on the
 * Tshinanu staging instance. Creates throwaway boards prefixed "zzz-e2e-" and
 * deletes them at the end (and defensively at the start).
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

$OWNER = 'Test 1';
$RECIPIENT = 'Test 2';
$RO = 'zzz-e2e-ro';
$COLLAB = 'zzz-e2e-collab';

$server = \OC::$server;
$userManager = $server->get(\OCP\IUserManager::class);
$userSession = $server->get(\OCP\IUserSession::class);
$rootFolder = $server->get(\OCP\Files\IRootFolder::class);
$shareManager = $server->get(\OCP\Share\IManager::class);
$request = $server->get(\OCP\IRequest::class);

// The classes under test, wired by hand (no HTTP layer in a CLI script).
$gateway = new NextcloudShareGateway($shareManager, $rootFolder, $userSession);
$shareService = new BoardShareService($gateway);
$receivedLocator = new ReceivedBoardLocator($shareManager, $userSession);
$markdown = new MarkdownRenderer();
$boardCtrl = new BoardController($request, $userSession, $rootFolder, $shareService, $receivedLocator);
$cardCtrl = new CardController($request, $userSession, $rootFolder, $markdown, $receivedLocator, $shareService);

// --- harness ---------------------------------------------------------------

$pass = 0;
$fail = 0;

function actAs(string $uid): void {
	$u = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
	if ($u === null) {
		fwrite(STDERR, "FATAL: user '$uid' not found\n");
		exit(2);
	}
	\OC::$server->get(\OCP\IUserSession::class)->setUser($u);
	\OC_User::setUserId($uid);
	\OC_Util::tearDownFS();
	\OC_Util::setupFS($uid);
}

function check(string $label, bool $ok): void {
	global $pass, $fail;
	if ($ok) {
		$pass++;
		echo "  \e[32mPASS\e[0m  $label\n";
	} else {
		$fail++;
		echo "  \e[31mFAIL\e[0m  $label\n";
	}
}

/** True when a DataResponse denies the write with 403 read_only. */
function isReadOnly(mixed $r): bool {
	return $r instanceof DataResponse
		&& $r->getStatus() === 403
		&& (($r->getData()['error'] ?? null) === 'read_only');
}

function status(mixed $r): int {
	return $r instanceof DataResponse ? $r->getStatus() : -1;
}

/** Best-effort teardown of a throwaway board owned by the current user. */
function dropBoard(BoardController $boardCtrl, string $boardId): void {
	try {
		$boardCtrl->destroy($boardId);
	} catch (\Throwable) {
		// ignore — may not exist
	}
}

// --- 0. clean any leftovers from a previous run ----------------------------

echo "\n[0] Cleanup leftovers (as $OWNER)\n";
actAs($OWNER);
dropBoard($boardCtrl, $RO);
dropBoard($boardCtrl, $COLLAB);

// --- 1. setup: two boards + a card each, shared to the recipient -----------

echo "[1] Setup boards + shares (as $OWNER)\n";
actAs($OWNER);

$r = $boardCtrl->create('zzz e2e ro', '#8a2be2');
check("owner creates read-only board ($RO) -> 201", status($r) === 201);
$r = $cardCtrl->create($RO, 'RO card', 'Backlog');
check('owner creates a card on it -> 201', status($r) === 201);
$roCardId = $r->getData()['card']['id'] ?? '';
// Canonical column value as the owner sees it — the integrity check below
// compares against THIS (representation-agnostic), not a hard-coded name.
$roColBefore = $cardCtrl->show($RO, $roCardId)->getData()['card']['column'] ?? '';

$r = $boardCtrl->create('zzz e2e collab', '#2e8b57');
check("owner creates collaborate board ($COLLAB) -> 201", status($r) === 201);
$r = $cardCtrl->create($COLLAB, 'Collab card', 'Backlog');
check('owner creates a card on it -> 201', status($r) === 201);
$collabCardId = $r->getData()['card']['id'] ?? '';

$shareService->share($RO, 'user', $RECIPIENT, 'read');
echo "      shared $RO to $RECIPIENT as READ\n";
$shareService->share($COLLAB, 'user', $RECIPIENT, 'collaborate');
echo "      shared $COLLAB to $RECIPIENT as COLLABORATE\n";

// --- 2. recipient: read allowed, every write on the read-only board refused

echo "[2] Recipient on the READ-ONLY board (as $RECIPIENT)\n";
actAs($RECIPIENT);

check('receivedPermission is READ-only (bit UPDATE absent)',
	$shareService->receivedPermission($RO) !== null
	&& !\OCA\SovereignKanbanMdPersistence\Sharing\SharePermissions::allowsWrite((int) $shareService->receivedPermission($RO)));

$r = $cardCtrl->index($RO);
$cardsVisible = 0;
foreach (($r->getData()['cards'] ?? []) as $col) {
	$cardsVisible += count($col);
}
check('recipient CAN read the board (index 200, card visible)', status($r) === 200 && $cardsVisible >= 1);

check('move card -> 403 read_only', isReadOnly($cardCtrl->move($RO, $roCardId, 'En cours')));
check('create card -> 403 read_only', isReadOnly($cardCtrl->create($RO, 'sneaky', 'Backlog')));
check('update card -> 403 read_only', isReadOnly($cardCtrl->update($RO, $roCardId, 'hacked title')));
check('delete card -> 403 read_only', isReadOnly($cardCtrl->destroy($RO, $roCardId)));
check('add comment -> 403 read_only', isReadOnly($cardCtrl->addComment($RO, $roCardId, 'nope')));
check('add column (board-level) -> 403 read_only', isReadOnly($boardCtrl->addColumn($RO, 'Injected')));

echo "[2b] Recipient on the COLLABORATE board — writes must WORK (no false positive)\n";
check('collaborate: receivedPermission grants write',
	\OCA\SovereignKanbanMdPersistence\Sharing\SharePermissions::allowsWrite((int) $shareService->receivedPermission($COLLAB)));
$r = $cardCtrl->move($COLLAB, $collabCardId, 'En cours');
check('collaborate: move card -> 200', status($r) === 200);

// --- 3. integrity + owner regression --------------------------------------

echo "[3] Integrity + owner regression (as $OWNER)\n";
actAs($OWNER);

// The refused writes must have left the read-only board's card untouched:
// same column as before the recipient's attempts (403 must return before any
// write, so nothing moved).
$after = $cardCtrl->show($RO, $roCardId)->getData()['card']['column'] ?? '__gone__';
check("recipient's refused writes left the card untouched (column unchanged)",
	$roColBefore !== '' && $after === $roColBefore);

$r = $cardCtrl->create($RO, 'owner still writes', 'Backlog');
check('owner can still create on their own board -> 201', status($r) === 201);
$r = $cardCtrl->move($RO, $roCardId, 'Terminé');
check('owner can still move on their own board -> 200', status($r) === 200);

// --- 4. teardown -----------------------------------------------------------

echo "[4] Teardown (as $OWNER)\n";
actAs($OWNER);
dropBoard($boardCtrl, $RO);
dropBoard($boardCtrl, $COLLAB);

// --- summary ---------------------------------------------------------------

echo "\n" . str_repeat('─', 60) . "\n";
printf("Functional read-only enforcement: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
