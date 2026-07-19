<?php

/**
 * @file
 * Functional (e2e) test: the "done" status (completed_at) round-trips through the
 * card.md file, and the checklist count is read from the Markdown checkboxes.
 *
 * Alain, 2026-07-19: mark a card done with `completed_at: <instant>` (keeps the
 * "when"), reopen by clearing it; the done/total badge counts the '- [ ]'/'- [x]'
 * items already in the description — nothing extra stored.
 *
 * The point of doing this at the functional tier: completed_at is a NEW
 * frontmatter key. A unit test proves the value object; only a real write proves
 * the key lands in the file, survives a reload, and clears cleanly — the same
 * "normalizing is not writing to disk" gap that bit due_date.
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/completed_at_and_checklist.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: one throwaway board zzz-e2e-done under the test account, deleted
 * unconditionally. Touches no real card.
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
use OCP\AppFramework\Http\DataResponse;

$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — this test died before finishing. NOT a pass.\n");
		exit(70);
	}
});

$OWNER = 'Test 1';
$BOARD = 'zzz-e2e-done';

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
	$res = $boardCtrl->create($BOARD, '#0082c9');
	$boardId = $res->getData()['board']['id'] ?? $BOARD;

	// A card whose body carries a checklist: 1 done, 2 open → 1/3.
	$body = "Plan\n\n- [x] fait\n- [ ] à faire\n- [ ] encore\n";
	$res = $cardCtrl->create($boardId, 'Carte terminable', 'Backlog', $body);
	check('setup: card created', $res->getStatus() === 201, 'status ' . $res->getStatus());
	$cardId = $res->getData()['card']['id'];

	// --- checklist read from the Markdown ----------------------------------
	$detail = $cardCtrl->show($boardId, $cardId)->getData()['card'];
	check('[1] checklist total counted from the body', ($detail['checklist']['total'] ?? null) === 3, json_encode($detail['checklist'] ?? null));
	check('[2] checklist done counted from the body', ($detail['checklist']['done'] ?? null) === 1, json_encode($detail['checklist'] ?? null));
	check('[3] a new card is not done', array_key_exists('completed_at', $detail) && $detail['completed_at'] === null, var_export($detail['completed_at'] ?? 'MISSING', true));
	check('[3a] show returns created_at (ISO)', preg_match('/^\d{4}-\d{2}-\d{2}T/', (string) ($detail['created_at'] ?? '')) === 1, (string) ($detail['created_at'] ?? 'MISSING'));
	check('[3b] show returns modified (ISO, from the file mtime)', preg_match('/^\d{4}-\d{2}-\d{2}T/', (string) ($detail['modified'] ?? '')) === 1, (string) ($detail['modified'] ?? 'MISSING'));

	// --- mark done: completed_at set + persisted ---------------------------
	$instant = '2026-07-19T04:00:00Z';
	$res = $cardCtrl->update($boardId, $cardId, completed_at: $instant);
	check('[4] marking done returns 200', $res->getStatus() === 200, 'status ' . $res->getStatus());
	check('[5] response carries completed_at', ($res->getData()['card']['completed_at'] ?? null) === $instant, var_export($res->getData()['card']['completed_at'] ?? null, true));

	$onDisk = cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent();
	check('[6] completed_at written to the file', str_contains($onDisk, 'completed_at'), '');
	$reloaded = Card::fromMarkdown($onDisk);
	check('[7] completed_at round-trips through the file', $reloaded->completed_at === $instant, var_export($reloaded->completed_at, true));

	// An unrelated edit must not drop the done status.
	$cardCtrl->update($boardId, $cardId, priority: '2');
	$after = Card::fromMarkdown(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent());
	check('[8] completed_at survives an unrelated edit', $after->completed_at === $instant, var_export($after->completed_at, true));

	// --- reopen: clear with '' --------------------------------------------
	$res = $cardCtrl->update($boardId, $cardId, completed_at: '');
	check('[9] reopening returns 200', $res->getStatus() === 200, 'status ' . $res->getStatus());
	$afterReopen = cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent();
	check('[10] completed_at removed from the file', !str_contains($afterReopen, 'completed_at'), '');
	$reopenData = $res->getData()['card'];
	check('[11] response shows not-done', array_key_exists('completed_at', $reopenData) && $reopenData['completed_at'] === null, var_export($reopenData['completed_at'] ?? 'MISSING', true));

	// --- guard: null leaves it unchanged (not cleared) ---------------------
	$cardCtrl->update($boardId, $cardId, completed_at: $instant);
	$cardCtrl->update($boardId, $cardId, title: 'Renommée'); // completed_at defaults null
	$stillDone = Card::fromMarkdown(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent());
	check('[12] omitting completed_at leaves it unchanged', $stillDone->completed_at === $instant, var_export($stillDone->completed_at, true));

	printf("\n%d passed, %d failed\n", $pass, $fail);
} finally {
	teardown($OWNER, $BOARD);
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
