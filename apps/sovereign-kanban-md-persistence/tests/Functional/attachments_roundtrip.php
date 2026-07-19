<?php

/**
 * @file
 * Functional (e2e) test: card attachments round-trip through the attachments/
 * folder (Alain, 2026-07-19). The folder IS the list — nothing mirrored in the
 * frontmatter. Upload is base64 (capped 10 MiB); the name is basename'd so no
 * upload can escape the folder.
 *
 * FALSIFICATION (this test can redden):
 *   - Remove the `strlen($content) > MAX` check in addAttachment → [guard-too-large]
 *     goes red.
 *   - Drop the basename() in FileCardRepository::saveAttachment → [traversal-contained]
 *     goes red (the file escapes attachments/).
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/attachments_roundtrip.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: one throwaway board zzz-e2e-attach under the test account, deleted
 * unconditionally.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
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
$BOARD = 'zzz-e2e-attach';

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

function names(array $attachments): array {
	return array_map(static fn (array $a): string => $a['name'], $attachments);
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
	$cardId = $cardCtrl->create($boardId, 'Carte avec pièces', 'Backlog')->getData()['card']['id'];

	// --- upload round-trips ------------------------------------------------
	$payload = "Bonjour les pièces jointes\n";
	$res = $cardCtrl->addAttachment($boardId, $cardId, 'note.txt', base64_encode($payload));
	check('[upload] uploading returns 201', $res->getStatus() === 201, 'status ' . $res->getStatus());
	$list = $res->getData()['attachments'];
	check('[list] the attachment is listed', in_array('note.txt', names($list), true), json_encode(names($list)));
	check('[size] the listed size matches the bytes', ($list[0]['size'] ?? -1) === strlen($payload), var_export($list[0]['size'] ?? null, true));

	$onDisk = cardDir($OWNER, $BOARD, $cardId)->get('attachments/note.txt')->getContent();
	check('[on-disk] the file holds the uploaded bytes', $onDisk === $payload, '');

	// --- download returns the same bytes -----------------------------------
	$dl = $cardCtrl->downloadAttachment($boardId, $cardId, 'note.txt');
	check('[download] download returns the bytes', method_exists($dl, 'render') && $dl->render() === $payload, '');

	// --- activity journals attach ------------------------------------------
	$events = $cardCtrl->activity($boardId, $cardId)->getData()['activity'];
	$attached = array_filter($events, static fn ($e) => ($e['action'] ?? '') === 'attached');
	check('[journal-attach] an "attached" event is journaled', count($attached) === 1, count($events) . ' event(s)');

	// --- path traversal is contained (basename) ----------------------------
	$cardCtrl->addAttachment($boardId, $cardId, '../../escape.txt', base64_encode('x'));
	$contained = cardDir($OWNER, $BOARD, $cardId)->nodeExists('attachments/escape.txt');
	$escaped = cardDir($OWNER, $BOARD, $cardId)->getParent()->nodeExists('escape.txt');
	check('[traversal-contained] a ../ name lands inside attachments/', $contained && !$escaped, "contained=" . var_export($contained, true) . " escaped=" . var_export($escaped, true));

	// --- guards ------------------------------------------------------------
	check('[guard-badb64] invalid base64 is rejected', $cardCtrl->addAttachment($boardId, $cardId, 'x.bin', '!!!not base64!!!')->getStatus() === 400, '');
	check('[guard-empty] empty content is rejected', $cardCtrl->addAttachment($boardId, $cardId, 'x.bin', base64_encode(''))->getStatus() === 400, '');
	check('[guard-dotfile] a dotfile name is rejected', $cardCtrl->addAttachment($boardId, $cardId, '.hidden', base64_encode('x'))->getStatus() === 400, '');
	$big = base64_encode(str_repeat('A', 10 * 1024 * 1024 + 1));
	check('[guard-too-large] over 10 MiB is rejected (413)', $cardCtrl->addAttachment($boardId, $cardId, 'big.bin', $big)->getStatus() === 413, '');

	// --- delete removes the file -------------------------------------------
	$res = $cardCtrl->deleteAttachment($boardId, $cardId, 'note.txt');
	check('[delete] deleting returns 200', $res->getStatus() === 200, 'status ' . $res->getStatus());
	check('[delete-gone] the file is gone from the list', !in_array('note.txt', names($res->getData()['attachments']), true), json_encode(names($res->getData()['attachments'])));
	check('[delete-disk] the file is gone from disk', !cardDir($OWNER, $BOARD, $cardId)->nodeExists('attachments/note.txt'), '');
	$events = $cardCtrl->activity($boardId, $cardId)->getData()['activity'];
	check('[journal-detach] a "detached" event is journaled', count(array_filter($events, static fn ($e) => ($e['action'] ?? '') === 'detached')) === 1, '');

	printf("\n%d passed, %d failed\n", $pass, $fail);
} finally {
	teardown($OWNER, $BOARD);
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
