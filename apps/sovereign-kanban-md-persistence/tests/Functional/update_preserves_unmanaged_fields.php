<?php

/**
 * @file
 * Functional (e2e) test: an update must not destroy what it does not manage.
 *
 * WHY THIS EXISTS. On 2026-07-15 the card.md format gained rule 5 — "a key this
 * app does not know is written back untouched" — with a green conformance suite
 * proving it on the Card value object. The spec says it. The README's promise
 * that cards are "readable in Obsidian" depends on it.
 *
 * It was false through the only path a user has. CardController::update()
 * rebuilds the Card field by field and simply never passed $extra along, so
 * every edit from the browser silently deleted the frontmatter keys the user
 * had added in their own file. Same shape as the due_date truncation found the
 * same evening, in the same function, and the same reason it was missed: the
 * fix was applied to Card and to withColumn(), and the OTHER places that
 * reconstruct a Card were never swept.
 *
 * The rule this test defends is the sovereignty claim itself: an app that
 * silently eats what it does not understand does not host your data, it borrows
 * it. So the assertion is not "extra keys are nice to have" — it is that the app
 * has no business deleting a line the user wrote.
 *
 * It also covers `procedures`, which update() deliberately does not accept: a
 * field the API cannot change must still survive the API being called.
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/update_preserves_unmanaged_fields.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: creates a throwaway board prefixed "zzz-e2e-keep-" under the test
 * account only, deleted unconditionally. Touches no real card.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Service\MarkdownRenderer;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ReceivedBoardLocator;
use OCP\AppFramework\Http\DataResponse;

// --- abnormal-termination guard — DO NOT REMOVE ----------------------------
// After OC_Util::setupFS(), an uncaught exception is swallowed by Nextcloud and
// this script exits 0 — reporting SUCCESS while dying halfway, teardown skipped.
$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — this test died before finishing. NOT a pass.\n");
		fwrite(STDERR, "   Teardown did NOT run: check for a leftover zzz-e2e-keep-* board.\n");
		exit(70);
	}
});

$OWNER = 'Test 1';
$BOARD = 'zzz-e2e-keep';

$server = \OC::$server;
$userSession = $server->get(\OCP\IUserSession::class);
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
		printf("\e[32m✅\e[0m %-54s %s\n", $label, $detail);
	} else {
		$fail++;
		printf("\e[31m❌\e[0m %-54s %s\n", $label, $detail);
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
	if (!($res instanceof DataResponse) || $res->getStatus() >= 300) {
		fwrite(STDERR, "FATAL: could not create the board (status " . $res->getStatus() . ")\n");
		$completed = true;
		exit(1);
	}
	$boardId = $res->getData()['board']['id'] ?? $BOARD;

	// A card created with procedures — update() cannot change them, so they are
	// the control for "a field the API does not manage".
	$res = $cardCtrl->create($boardId, 'Réunion du conseil', 'Backlog', 'Corps.', ['Élection sans candidat']);
	check('setup: card created with procedures', $res->getStatus() === 201, 'status ' . $res->getStatus());
	$cardId = $res->getData()['card']['id'];

	// Now the user opens THEIR file — in Obsidian, in Files, in vim — and adds
	// keys of their own. This is exactly what the README invites them to do.
	$dir = cardDir($OWNER, $BOARD, $cardId);
	$file = $dir->get('card.md');
	$before = $file->getContent();
	$file->putContent(preg_replace(
		'/^---\R/',
		"---\naliases:\n  - ancien-nom\ncssclass: kanban-large\nma-cle-a-moi: une valeur\n",
		$before,
		1,
	));
	check(
		'setup: the user added 3 keys of their own to the file',
		str_contains($file->getContent(), 'ma-cle-a-moi'),
	);

	// The app must READ them without choking.
	$res = $cardCtrl->show($boardId, $cardId);
	check('the card still opens with unknown keys present', $res->getStatus() === 200, 'status ' . $res->getStatus());

	// --- THE ASSERTION: one ordinary edit from the browser ------------------
	$res = $cardCtrl->update($boardId, $cardId, title: 'Réunion du conseil (reportée)');
	check('the edit itself succeeds', $res->getStatus() === 200, 'status ' . $res->getStatus());

	$after = cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent();

	check(
		'[1] the user\'s `aliases` survived the edit',
		str_contains($after, 'aliases'),
		str_contains($after, 'aliases') ? '' : 'DELETED by an edit the user did not ask for',
	);
	check(
		'[2] the user\'s `cssclass` survived the edit',
		str_contains($after, 'cssclass'),
		str_contains($after, 'cssclass') ? '' : 'DELETED',
	);
	check(
		'[3] the user\'s own `ma-cle-a-moi` survived the edit',
		str_contains($after, 'ma-cle-a-moi'),
		str_contains($after, 'ma-cle-a-moi') ? '' : 'DELETED',
	);

	// The control: a field the API cannot even change must survive it.
	$card = Card::fromMarkdown($after);
	check(
		'[4] `procedures` survived, though update() cannot set them',
		$card->procedures === ['Élection sans candidat'],
		json_encode($card->procedures, JSON_UNESCAPED_UNICODE),
	);

	// And the edit did what it was asked to do.
	check('[5] the title was actually changed', $card->title === 'Réunion du conseil (reportée)', $card->title);
	check('[6] the body was left alone', $card->description === 'Corps.', var_export($card->description, true));

	// --- a second edit must not degrade further -----------------------------
	$cardCtrl->update($boardId, $cardId, priority: 'haute', tags: ['infra', ' urgent '], phase: '2');
	$after2 = cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent();
	$card2 = Card::fromMarkdown($after2);
	check(
		'[7] unknown keys still there after a second edit',
		str_contains($after2, 'aliases') && str_contains($after2, 'ma-cle-a-moi'),
	);
	check('[8] tags are trimmed and stored', $card2->tags === ['infra', 'urgent'], json_encode($card2->tags));
	check('[9] priority stored', $card2->priority === 'haute', var_export($card2->priority, true));
	check('[10] phase stored as an int', $card2->phase === 2, var_export($card2->phase, true));
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
