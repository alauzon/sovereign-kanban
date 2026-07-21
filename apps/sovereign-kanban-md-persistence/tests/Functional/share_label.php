<?php

/**
 * @file
 * A share must be listed by a name a human can read, not by its raw id.
 *
 * Steve, 2026-07-20: sharing his board to his team « NéoVillage » listed it as
 * « équipe · RZSycf3F2ywXIz9b9c9asbSPqkD6Dxr · collaboration ». A circle's id is
 * its address, not its name — and he could only tell which team it was because
 * he had just created the share himself.
 *
 * Nextcloud already carries the answer: IShare::getSharedWithDisplayName()
 * returns « NéoVillage (steve lauzier est propriétaire de l'équipe) » for that
 * exact circle (checked on CT204). We simply never asked for it.
 *
 * RED before 'label' is added to listShares(), GREEN after.
 *
 * Usage: runuser -u www-data -- php share_label.php   ·   0 ok · 1 fail · 2 setup.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;

$uid = 'Alain Lauzon';
$groupId = 'zzz-e2e-share-label';
$prettyName = 'Équipe de test lisible';

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
// A group whose display name differs from its id is the readable stand-in for
// Steve's circle: same shape, no real team touched.
if (method_exists($group, 'setDisplayName')) {
	$group->setDisplayName($prettyName);
}

$root = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$kanban = $root->nodeExists('Kanban') ? $root->get('Kanban') : $root->newFolder('Kanban');
$boardRepo = new FileBoardRepository(new NextcloudStorage($kanban));
$board = Board::create('zzz Share Label', '#0082c9');
$boardRepo->create($board);
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

	$listed = \OC::$server->get(BoardShareService::class)->listShares($bid);
	$mine = null;
	foreach ($listed as $row) {
		if (($row['with'] ?? null) === $groupId) {
			$mine = $row;
		}
	}

	check('[1] le partage est listé', $mine !== null, 'lignes: ' . count($listed));
	check(
		'[2] la ligne porte une étiquette lisible',
		$mine !== null && array_key_exists('label', $mine) && ($mine['label'] ?? '') !== '',
		'clés: ' . ($mine !== null ? implode(', ', array_keys($mine)) : '—'),
	);

	$displayName = $groupManager->get($groupId)?->getDisplayName();
	if ($displayName === $prettyName) {
		check(
			'[3] et cette étiquette est le NOM, pas l’identifiant',
			($mine['label'] ?? null) === $prettyName,
			'étiquette: ' . var_export($mine['label'] ?? null, true),
		);
	} else {
		echo "\e[33m—\e[0m [3] ignoré : ce backend de groupes ne porte pas de nom d’affichage distinct\n";
	}
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
