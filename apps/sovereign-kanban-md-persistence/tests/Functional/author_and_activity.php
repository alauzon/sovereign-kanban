<?php

/**
 * @file
 * Functional (e2e) test: the card author (written once at creation) round-trips
 * through card.md, and the sovereign activity journal (option C, Alain 2026-07-19)
 * records what changed in activity.jsonl next to the card.
 *
 * Why the functional tier: `author` is a NEW frontmatter key and activity.jsonl is
 * a NEW sidecar file. A unit test proves the value object; only a real write proves
 * the key lands in the file, survives an unrelated edit (the field-by-field drop
 * that already bit due_date and would silently erase the author), and that the
 * journal actually appends one line per action.
 *
 * FALSIFICATION (this test can redden):
 *   - Remove `author: $card->author` from the update() reconstruction in
 *     CardController → [author-survives-edit] goes red.
 *   - Delete the appendActivity() call in create() → [created-event] goes red.
 *   - Delete the appendActivity() call in update() → [updated-event] goes red.
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/author_and_activity.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: one throwaway board zzz-e2e-activity under the test account, deleted
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

$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — this test died before finishing. NOT a pass.\n");
		exit(70);
	}
});

$OWNER = 'Test 1';
$BOARD = 'zzz-e2e-activity';

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

/** Count journal events with the given action. */
function countAction(array $events, string $action): int {
	return count(array_filter($events, static fn (array $e): bool => ($e['action'] ?? null) === $action));
}

/** The first journal event with the given action, or null. */
function firstAction(array $events, string $action): ?array {
	foreach ($events as $e) {
		if (($e['action'] ?? null) === $action) {
			return $e;
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

	$res = $cardCtrl->create($boardId, 'Carte tracée', 'Backlog');
	check('setup: card created', $res->getStatus() === 201, 'status ' . $res->getStatus());
	$card = $res->getData()['card'];
	$cardId = $card['id'];

	// --- author: set at creation, exposed, round-trips ---------------------
	check('[author-in-response] create returns author = actor uid', ($card['author'] ?? null) === $OWNER, var_export($card['author'] ?? null, true));
	check('[author-label] create returns a display label', ($card['author_label'] ?? null) !== null && $card['author_label'] !== '', var_export($card['author_label'] ?? null, true));
	$onDisk = cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent();
	check('[author-in-file] author written to card.md', str_contains($onDisk, 'author'), '');
	check('[author-roundtrip] author round-trips through the file', Card::fromMarkdown($onDisk)->author === $OWNER, var_export(Card::fromMarkdown($onDisk)->author, true));

	// --- activity: created event -------------------------------------------
	$events = $cardCtrl->activity($boardId, $cardId)->getData()['activity'];
	check('[created-event] a "created" event is journaled', countAction($events, 'created') === 1, count($events) . ' event(s)');
	$created = firstAction($events, 'created');
	check('[created-actor] created event actor = uid', ($created['actor'] ?? null) === $OWNER, var_export($created['actor'] ?? null, true));
	check('[created-ts] created event has an ISO ts', preg_match('/^\d{4}-\d{2}-\d{2}T/', (string) ($created['ts'] ?? '')) === 1, (string) ($created['ts'] ?? 'MISSING'));
	check('[actor-label] endpoint resolves actor_label', ($created['actor_label'] ?? null) !== null, var_export($created['actor_label'] ?? null, true));
	check('[jsonl-file] activity.jsonl written next to card.md', cardDir($OWNER, $BOARD, $cardId)->nodeExists('activity.jsonl'), '');

	// --- activity: updated event with the changed field --------------------
	$cardCtrl->update($boardId, $cardId, priority: '1');
	$events = $cardCtrl->activity($boardId, $cardId)->getData()['activity'];
	$updated = firstAction($events, 'updated');
	check('[updated-event] editing a field journals "updated"', $updated !== null, count($events) . ' event(s)');
	check('[updated-fields] the changed field is recorded (English id)', in_array('priority', $updated['detail']['fields'] ?? [], true), json_encode($updated['detail'] ?? null));

	// --- author survives an unrelated edit (falsifies the field-drop bug) ---
	$afterEdit = Card::fromMarkdown(cardDir($OWNER, $BOARD, $cardId)->get('card.md')->getContent());
	check('[author-survives-edit] author untouched by an unrelated edit', $afterEdit->author === $OWNER, var_export($afterEdit->author, true));

	// --- a no-op update journals nothing new -------------------------------
	$before = count($cardCtrl->activity($boardId, $cardId)->getData()['activity']);
	$cardCtrl->update($boardId, $cardId, priority: '1'); // same value → no change
	$afterNoop = count($cardCtrl->activity($boardId, $cardId)->getData()['activity']);
	check('[noop-silent] an update that changes nothing journals nothing', $afterNoop === $before, "before $before, after $afterNoop");

	// --- done / reopened are their own verbs -------------------------------
	$cardCtrl->update($boardId, $cardId, completed_at: '2026-07-19T04:00:00Z');
	$cardCtrl->update($boardId, $cardId, completed_at: '');
	$events = $cardCtrl->activity($boardId, $cardId)->getData()['activity'];
	check('[done-event] marking done journals "done"', countAction($events, 'done') === 1, count($events) . ' event(s)');
	check('[reopened-event] reopening journals "reopened"', countAction($events, 'reopened') === 1, '');

	// --- comment journals "commented" --------------------------------------
	$cardCtrl->addComment($boardId, $cardId, 'Un mot.');
	$events = $cardCtrl->activity($boardId, $cardId)->getData()['activity'];
	check('[commented-event] a comment journals "commented"', countAction($events, 'commented') === 1, '');

	// --- chronological order (oldest first) --------------------------------
	check('[order] first event is the creation', ($events[0]['action'] ?? null) === 'created', var_export($events[0]['action'] ?? null, true));

	printf("\n%d passed, %d failed\n", $pass, $fail);
} finally {
	teardown($OWNER, $BOARD);
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
