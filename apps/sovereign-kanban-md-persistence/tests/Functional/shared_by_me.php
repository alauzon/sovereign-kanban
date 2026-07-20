<?php

/**
 * @file
 * A board you OWN and share out must never come back as « shared WITH you ».
 *
 * receivedBoards() walks getSharedWith($uid) — which includes a group share the
 * user made themselves to a group they belong to. Alain, 2026-07-20: he shared
 * « Projets » with the group « Développement » (he is a member), and his own
 * board showed up under « Partagés avec vous », duplicated with his own list.
 * A board must be classed by OWNERSHIP first: mine, or received — never both.
 *
 * RED before the owner filter, GREEN after.
 *
 * Usage: runuser -u www-data -- php shared_by_me.php   ·   0 ok · 1 fail · 2 setup.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;

$uid = 'Test 1';
$groupId = 'zzz-e2e-shareback';
$userManager = \OC::$server->get(\OCP\IUserManager::class);
$groupManager = \OC::$server->get(\OCP\IGroupManager::class);
$shareManager = \OC::$server->get(\OCP\Share\IManager::class);

$user = $userManager->get($uid);
if ($user === null) {
	fwrite(STDERR, "FATAL: compte '$uid' absent\n");
	exit(2);
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($user);
\OC_Util::setupFS($uid);

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
	global $pass, $fail;
	echo ($ok ? "\e[32m✅\e[0m " : "\e[31m❌\e[0m ") . $label . ($detail !== '' ? "  $detail" : '') . "\n";
	$ok ? $pass++ : $fail++;
}

$group = $groupManager->get($groupId) ?? $groupManager->createGroup($groupId);
$group->addUser($user);

$root = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$kanban = $root->nodeExists('Kanban') ? $root->get('Kanban') : $root->newFolder('Kanban');
$repo = new FileBoardRepository(new NextcloudStorage($kanban));
$board = Board::create('zzz Share Back Test', '#0082c9');
$repo->create($board);
$bid = $board->id;
$share = null;

try {
	$share = $shareManager->newShare();
	$share->setNode($kanban->get($bid));
	$share->setShareType(\OCP\Share\IShare::TYPE_GROUP);
	$share->setSharedWith($groupId);
	$share->setSharedBy($uid);
	$share->setPermissions(\OCP\Constants::PERMISSION_ALL);
	$share = $shareManager->createShare($share);

	$svc = \OC::$server->get(BoardShareService::class);
	$receivedIds = array_map(static fn (array $b): string => (string) $b['id'], $svc->receivedBoards());

	check(
		'[1] mon propre tableau partagé ne revient PAS dans les « reçus »',
		!in_array($bid, $receivedIds, true),
		'reçus: ' . (implode(', ', $receivedIds) ?: '(aucun)'),
	);
	check('[2] il reste bien dans MES tableaux', $repo->find($bid) !== null);
	check(
		'[3] il est signalé comme « partagé par vous »',
		in_array($bid, $svc->boardsSharedByMe(), true),
		'partagés par moi: ' . (implode(', ', $svc->boardsSharedByMe()) ?: '(aucun)'),
	);
} finally {
	if ($share !== null) {
		$shareManager->deleteShare($share);
	}
	$kanban->get($bid)->delete();
	$group->removeUser($user);
	$group->delete();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
