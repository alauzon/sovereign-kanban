<?php

/**
 * @file
 * Functional (e2e) test: only a board's OWNER may share, list or revoke it.
 *
 * Complements readonly_enforcement.php. That one proves a read-only recipient
 * cannot WRITE the data; this one proves no recipient can RE-SHARE the board —
 * the privilege boundary rather than the data one. "No re-sharing" is a frozen
 * decision (documentation/08 §10-11).
 *
 * WHY THIS EXISTS, given the guards already look right. The owner-only policy
 * lives in BoardShareService and IS unit-tested — but over a FAKE gateway. What
 * has never been checked at any layer is whether the REAL ownership resolution,
 * against a booted Nextcloud, agrees. That gap is not theoretical here: this
 * codebase has already been bitten by scope-confused ownership. BoardController
 * used to read $folder->getPermissions() on a received board — the node comes
 * from $share->getNode(), resolved in the OWNER's scope, so it reported 31 and
 * never the recipient's right (fixed 2026-07-12). currentUserOwns() could carry
 * the same flaw. Only a real Nextcloud can answer.
 *
 * The sharpest assertion is the COLLABORATE one: write access must not imply
 * share access. A recipient who can legitimately move cards must still be
 * refused when re-sharing the board onward.
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/share_ownership_enforcement.php
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 *
 * SAFETY — read before changing $THIRD. Tshinanu (CT 211) is NOT an empty
 * sandbox: it hosts ~70 real community members. This test must aim a re-share
 * at a third party, and it asserts the attempt is REFUSED — but if the guard
 * has the hole we are hunting, the share LANDS. It must land somewhere
 * harmless. $THIRD is Alain's own test account, NEVER a community member, and
 * the teardown revokes defensively in case an assertion was wrong. Do not point
 * this at a real person to "make it more realistic".
 *
 * Accounts: 'Test 1' (owner), 'Test 2' (recipient) — pre-provisioned. Creates
 * throwaway boards prefixed "zzz-e2e-" and deletes them at the end (and
 * defensively at the start).
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\ShareController;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ReceivedBoardLocator;
use OCP\AppFramework\Http\DataResponse;

// --- abnormal-termination guard — DO NOT REMOVE ----------------------------
// Nextcloud installs an exception handler, and once OC_Util::setupFS() has run
// (i.e. from the first actAs()), an UNCAUGHT exception kills this script with
// exit code 0 — it reports SUCCESS while dying halfway. Verified on CT 211
// (2026-07-14). This bit us for real: a mutation run of this very test died at
// step [2] and reported exit 0.
//
// It matters twice here. A crash also skips the teardown below — so a silent
// death leaves throwaway boards, and possibly a leaked share, on an instance
// that hosts real people. Exit 70 makes that loud.
$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — this test died before finishing. NOT a pass.\n");
		fwrite(STDERR, "   Teardown did NOT run: check for leftover zzz-e2e-own-* boards and stray shares.\n");
		exit(70);
	}
});

$OWNER = 'Test 1';
$RECIPIENT = 'Test 2';
// Alain's own test account — see the SAFETY note above. Never a real member.
$THIRD = 'test@alainlauzon.com';
$RO = 'zzz-e2e-own-ro';
$COLLAB = 'zzz-e2e-own-collab';

$server = \OC::$server;
$userSession = $server->get(\OCP\IUserSession::class);
$userManager = $server->get(\OCP\IUserManager::class);
$groupManager = $server->get(\OCP\IGroupManager::class);
$rootFolder = $server->get(\OCP\Files\IRootFolder::class);
$shareManager = $server->get(\OCP\Share\IManager::class);
$request = $server->get(\OCP\IRequest::class);
$appConfig = $server->get(\OCP\IAppConfig::class);
$collaboratorSearch = $server->get(\OCP\Collaboration\Collaborators\ISearch::class);

// The classes under test, wired by hand (no HTTP layer in a CLI script).
$gateway = new NextcloudShareGateway($shareManager, $rootFolder, $userSession);
$shareService = new BoardShareService($gateway);
$receivedLocator = new ReceivedBoardLocator($shareManager, $userSession);
$boardCtrl = new BoardController($request, $userSession, $rootFolder, $shareService, $receivedLocator);
$shareCtrl = new ShareController(
	$request,
	$shareService,
	$userManager,
	$groupManager,
	$userSession,
	$appConfig,
	$collaboratorSearch,
);

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

/**
 * True when a DataResponse denies with 403 not_owner.
 *
 * Deliberately strict on the error code: a 400 invalid_request would also be a
 * refusal, but it would prove the request was malformed, not that ownership was
 * enforced. That is why $THIRD must be a REAL uid — otherwise the service
 * rejects on the recipient before it ever reaches the owner check, and the test
 * would pass for the wrong reason.
 */
function isNotOwner(mixed $r): bool {
	return $r instanceof DataResponse
		&& $r->getStatus() === 403
		&& (($r->getData()['error'] ?? null) === 'not_owner');
}

function status(mixed $r): int {
	return $r instanceof DataResponse ? $r->getStatus() : -1;
}

/**
 * Does the owner's share list mention this recipient at all?
 *
 * Representation-agnostic on purpose (same discipline as the integrity check in
 * readonly_enforcement.php): we do not hard-code the gateway's array shape, we
 * ask whether the uid appears anywhere in what the owner is shown. A leak makes
 * the uid appear and the assertion fails; no leak, no mention. The uid is
 * distinctive enough that an incidental match is not a realistic worry.
 */
function listMentions(array $shares, string $uid): bool {
	return str_contains(json_encode($shares) ?: '', $uid);
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

// --- 1. setup: two boards, shared to the recipient at both levels ----------

echo "[1] Setup boards + shares (as $OWNER)\n";
actAs($OWNER);

$r = $boardCtrl->create('zzz e2e own ro', '#8a2be2');
check("owner creates read-only board ($RO) -> 201", status($r) === 201);
$r = $boardCtrl->create('zzz e2e own collab', '#2e8b57');
check("owner creates collaborate board ($COLLAB) -> 201", status($r) === 201);

$roShareId = $shareService->share($RO, 'user', $RECIPIENT, 'read');
echo "      shared $RO to $RECIPIENT as READ (share $roShareId)\n";
$collabShareId = $shareService->share($COLLAB, 'user', $RECIPIENT, 'collaborate');
echo "      shared $COLLAB to $RECIPIENT as COLLABORATE (share $collabShareId)\n";

// --- 2. recipient: every ownership-gated operation must be refused ---------

echo "[2] Recipient tries to act as owner (as $RECIPIENT) — all must be refused\n";
actAs($RECIPIENT);

check("re-share the READ-ONLY board to a third party -> 403 not_owner",
	isNotOwner($shareCtrl->create($RO, 'user', $THIRD, 'read')));

// The sharpest one: COLLABORATE grants write, and write must NOT imply share.
check("re-share the COLLABORATE board to a third party -> 403 not_owner",
	isNotOwner($shareCtrl->create($COLLAB, 'user', $THIRD, 'read')));

check('list who else has access to the read-only board -> 403 not_owner',
	isNotOwner($shareCtrl->index($RO)));
check('list who else has access to the collaborate board -> 403 not_owner',
	isNotOwner($shareCtrl->index($COLLAB)));

check("revoke the owner's share of the read-only board -> 403 not_owner",
	isNotOwner($shareCtrl->destroy($RO, $roShareId)));
check("revoke the owner's share of the collaborate board -> 403 not_owner",
	isNotOwner($shareCtrl->destroy($COLLAB, $collabShareId)));

// --- 3. integrity: nothing leaked to the third party -----------------------

echo "[3] Integrity — the refused re-shares must have leaked nothing (as $OWNER)\n";
actAs($OWNER);

$roShares = $shareCtrl->index($RO)->getData()['shares'] ?? [];
check("read-only board: third party has NO access after the refused re-share",
	!listMentions($roShares, $THIRD));
$collabShares = $shareCtrl->index($COLLAB)->getData()['shares'] ?? [];
check("collaborate board: third party has NO access after the refused re-share",
	!listMentions($collabShares, $THIRD));

check("the owner's original share to $RECIPIENT survived the refused revoke",
	listMentions($roShares, $RECIPIENT));

// --- 4. no false positive: the OWNER can do all of it ----------------------

echo "[4] Owner can still share/list/revoke — no false positive (as $OWNER)\n";
actAs($OWNER);

check('owner lists shares -> 200', status($shareCtrl->index($RO)) === 200);

$r = $shareCtrl->create($RO, 'user', $THIRD, 'read');
check('owner shares to the third party -> 201', status($r) === 201);
$thirdShareId = $r->getData()['id'] ?? '';

$after = $shareCtrl->index($RO)->getData()['shares'] ?? [];
check('the owner-made share is really there', listMentions($after, $THIRD));

check('owner revokes it -> 200', status($shareCtrl->destroy($RO, $thirdShareId)) === 200);
$gone = $shareCtrl->index($RO)->getData()['shares'] ?? [];
check('after the revoke, the third party is gone again', !listMentions($gone, $THIRD));

// --- 5. teardown -----------------------------------------------------------
// Defensive: if an assertion above was WRONG and a re-share actually landed,
// the board is about to be deleted anyway — but revoke first so nothing
// lingers in the third party's Files if the delete misbehaves.

echo "[5] Teardown (as $OWNER)\n";
actAs($OWNER);
foreach ([$RO, $COLLAB] as $b) {
	try {
		foreach ($shareService->listShares($b) as $s) {
			if (isset($s['id']) && str_contains(json_encode($s) ?: '', $THIRD)) {
				$shareService->revoke($b, (string) $s['id']);
				echo "      defensive revoke: $THIRD had a share on $b\n";
			}
		}
	} catch (\Throwable) {
		// ignore — board may already be gone
	}
}
dropBoard($boardCtrl, $RO);
dropBoard($boardCtrl, $COLLAB);

// --- summary ---------------------------------------------------------------

echo "\n" . str_repeat('─', 60) . "\n";
printf("Functional share-ownership enforcement: %d passed, %d failed\n", $pass, $fail);

// Reached the end on the normal path — the shutdown guard may stand down and
// let the real verdict through.
$completed = true;
exit($fail === 0 ? 0 : 1);
