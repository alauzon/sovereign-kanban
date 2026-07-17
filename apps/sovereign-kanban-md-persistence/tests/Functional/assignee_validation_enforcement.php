<?php

/**
 * @file
 * Functional (e2e) test: an assignee must be a real Nextcloud account.
 *
 * D5 in the Deck→SK correspondence — "the worst gap" (1 529 events, 5th most
 * frequent gesture): SK's assignee field is free text split on commas, and the
 * server accepts anything. 'alain, steve', a typo, a display name, a phone
 * number — all get written into card.md as if they were account ids. Deck
 * validates assignment against the board's participants (assignUser). Escalated
 * by camille-ux-nextcloud; still open after the 2026-07-15 fixes.
 *
 * Scope of this v1 (announced default, 2026-07-17): an assignee must be an
 * EXISTING Nextcloud user id. Not yet "participant of the board" as in Deck —
 * that needs participant enumeration from a non-owner context, and will come
 * with the Vue NcSelect, which only offers participants in the first place.
 *
 * Contract:
 *   - update with an assignee that is no NC account   → 400, nothing written
 *   - update with valid accounts                       → 200, stored as given
 *   - a card whose FILE already carries stale garbage  → still loads, and an
 *     update that does not touch assignees still works (legacy tolerance:
 *     validation fires only on the assignees parameter, never on reads)
 *   - clearing (empty list) still works
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/assignee_validation_enforcement.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: one throwaway board "zzz-e2e-assign" under the test account, deleted
 * unconditionally. Touches no real card.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

// The guard goes FIRST — before every require, including Nextcloud's own.
$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — this test died before finishing. NOT a pass.\n");
		fwrite(STDERR, "   Teardown may NOT have run: check for a leftover zzz-e2e-assign board.\n");
		exit(70);
	}
});

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Service\MarkdownRenderer;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ReceivedBoardLocator;
use OCP\AppFramework\Http\DataResponse;

$OWNER = 'Test 1';
$OTHER = 'Test 2';
$BOARD = 'zzz-e2e-assign';

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

function cardFile(string $uid, string $board, string $cardId): ?\OCP\Files\File {
	$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
	$dir = $root->getUserFolder($uid)->get('Kanban/' . $board);
	foreach ($dir->getDirectoryListing() as $col) {
		if (!($col instanceof \OCP\Files\Folder)) {
			continue;
		}
		foreach ($col->getDirectoryListing() as $c) {
			if ($c instanceof \OCP\Files\Folder && str_starts_with($c->getName(), substr($cardId, 0, 8))) {
				return $c->get('card.md');
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
		fwrite(STDERR, "FATAL: could not create the board (status " . $res->getStatus() . ")\n");
		$completed = true;
		exit(1);
	}
	$boardId = $res->getData()['board']['id'] ?? $BOARD;

	$res = $cardCtrl->create($boardId, 'Carte assignable', 'Backlog');
	check('setup: card created', $res->getStatus() === 201, 'status ' . $res->getStatus());
	$cardId = $res->getData()['card']['id'];

	// --- [1] a ghost account must be refused, and nothing written -----------
	$before = cardFile($OWNER, $BOARD, $cardId)->getContent();
	$res = $cardCtrl->update($boardId, $cardId, assignees: ['fantome-xyz-9999']);
	check(
		'[1] a non-existent account is refused (400)',
		$res->getStatus() === 400 && ($res->getData()['error'] ?? '') === 'invalid_assignee',
		'status ' . $res->getStatus() . ' error=' . var_export($res->getData()['error'] ?? null, true),
	);
	check(
		'[1] the refusal names the culprit',
		in_array('fantome-xyz-9999', $res->getData()['invalid'] ?? [], true),
		json_encode($res->getData()['invalid'] ?? null),
	);
	check(
		'[1] the file was not touched by the refusal',
		cardFile($OWNER, $BOARD, $cardId)->getContent() === $before,
	);

	// --- [2] the exact garbage the free-text field produces today ----------
	// One valid id and one typo: all-or-nothing, no silent partial write.
	$res = $cardCtrl->update($boardId, $cardId, assignees: [$OTHER, 'stev']);
	check(
		'[2] one bad entry rejects the whole list (no partial write)',
		$res->getStatus() === 400,
		'status ' . $res->getStatus(),
	);
	$reloaded = Card::fromMarkdown(cardFile($OWNER, $BOARD, $cardId)->getContent());
	check('[2] still no assignee on the card', $reloaded->assignees === [], json_encode($reloaded->assignees));

	// --- [3] valid accounts pass and are stored -----------------------------
	$res = $cardCtrl->update($boardId, $cardId, assignees: [$OWNER, $OTHER]);
	check(
		'[3] two real accounts are accepted',
		$res->getStatus() === 200 && ($res->getData()['card']['assignees'] ?? null) === [$OWNER, $OTHER],
		'status ' . $res->getStatus() . ' → ' . json_encode($res->getData()['card']['assignees'] ?? null),
	);

	// --- [4] clearing still works -------------------------------------------
	$res = $cardCtrl->update($boardId, $cardId, assignees: []);
	check(
		'[4] clearing the assignees still works',
		$res->getStatus() === 200 && ($res->getData()['card']['assignees'] ?? null) === [],
		'status ' . $res->getStatus(),
	);

	// --- [5] legacy tolerance: stale garbage in the FILE must not brick -----
	// Files written before validation exist (free text was accepted for weeks).
	// They must still load, and an update that does not touch assignees must
	// still succeed — validation fires on the parameter, never on the past.
	$file = cardFile($OWNER, $BOARD, $cardId);
	$file->putContent(str_replace(
		"title: 'Carte assignable'",
		"title: 'Carte assignable'\nassignees:\n  - 'vieux fantome'",
		$file->getContent(),
	));
	$res = $cardCtrl->show($boardId, $cardId);
	check(
		'[5] a card with stale garbage still loads',
		$res->getStatus() === 200 && ($res->getData()['card']['assignees'] ?? null) === ['vieux fantome'],
		'status ' . $res->getStatus(),
	);
	$res = $cardCtrl->update($boardId, $cardId, title: 'Titre changé');
	check(
		'[5] an update not touching assignees still succeeds',
		$res->getStatus() === 200,
		'status ' . $res->getStatus(),
	);
	$reloaded = Card::fromMarkdown(cardFile($OWNER, $BOARD, $cardId)->getContent());
	check(
		'[5] and the stale assignee was carried, not silently dropped',
		$reloaded->assignees === ['vieux fantome'],
		json_encode($reloaded->assignees, JSON_UNESCAPED_UNICODE),
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
