<?php

/**
 * @file
 * Fixture for readonly-ui.spec.js: Test 2 creates a board with one card and
 * shares it to Test 1 as READ-ONLY. Run over SSH from the spec's beforeAll /
 * afterAll. Idempotent teardown.
 *
 * Usage (as www-data on CT 211):
 *   php ro-fixture.php setup     → creates + shares, prints the board slug
 *   php ro-fixture.php teardown  → deletes it
 *
 * SAFETY: board prefixed zzz-e2e-ro, under the two test accounts only.
 */

require_once '/var/www/nextcloud/lib/base.php';

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;

$OWNER = 'Test 2';
$RECIPIENT = 'Test 1';
$BOARD = 'zzz-e2e-ro';
$action = $argv[1] ?? '';

$server = \OC::$server;
$rootFolder = $server->get(\OCP\Files\IRootFolder::class);
$userSession = $server->get(\OCP\IUserSession::class);
$shareManager = $server->get(\OCP\Share\IManager::class);

function actAs(string $uid): void {
	$u = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
	if ($u === null) {
		fwrite(STDERR, "no user $uid\n");
		exit(2);
	}
	\OC::$server->get(\OCP\IUserSession::class)->setUser($u);
	\OC_Util::setupFS($uid);
}

function kanbanRoot(string $uid): \OCP\Files\Folder {
	$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
	$home = $root->getUserFolder($uid);
	$node = $home->nodeExists('Kanban') ? $home->get('Kanban') : $home->newFolder('Kanban');

	return $node;
}

actAs($OWNER);

if ($action === 'teardown') {
	try {
		kanbanRoot($OWNER)->get($BOARD)->delete();
	} catch (\Throwable $e) {
		// already gone
	}
	echo "teardown ok\n";
	exit(0);
}

if ($action !== 'setup') {
	fwrite(STDERR, "usage: ro-fixture.php setup|teardown\n");
	exit(2);
}

// Clean slate, then create the board + one card as Test 2.
try {
	kanbanRoot($OWNER)->get($BOARD)->delete();
} catch (\Throwable $e) {
}

$kanban = kanbanRoot($OWNER);
$boardRepo = new FileBoardRepository(new NextcloudStorage($kanban));
$boardRepo->create(Board::create($BOARD, '#0082c9'));

$boardFolder = $kanban->get($BOARD);
$cardRepo = new FileCardRepository(new NextcloudStorage($boardFolder));
$cardRepo->save(Card::create('Carte en lecture seule', '01-Backlog'));

// Share it READ-ONLY to Test 1, through the real gateway (owner-only policy).
$gateway = new NextcloudShareGateway($shareManager, $rootFolder, $userSession);
$shareService = new BoardShareService($gateway);
$shareService->share($BOARD, 'user', $RECIPIENT, 'read');

echo $BOARD . "\n";
