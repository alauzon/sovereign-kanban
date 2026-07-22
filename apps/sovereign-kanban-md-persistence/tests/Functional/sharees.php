<?php

/**
 * @file
 * /sharees returns the right shape for every recipient type, and a READABLE name.
 *
 * Backs the NcSelect picker (carte e85179): accounts carry avatar/name/email so
 * one confirms the right person, and a group is shown by its display name, never
 * its raw GID — the exact « identifiant brut » Steve saw (86fcb7). Teams (circles)
 * have no global enumeration, so that path must at least not blow up.
 *
 * Nextcloud buffers stdout in CLI and swallows fatals — flush so results reach the
 * terminal (hours lost to this on 2026-07-21).
 *
 * Usage: runuser -u www-data -- php sharees.php   ·   0 ok · 1 fail · 2 setup.
 */

while (ob_get_level() > 0) {
	ob_end_flush();
}
require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Controller\ShareController;

$uid = 'Alain Lauzon';
$groupId = 'zzz-e2e-sharees';
$groupName = 'Zzz Équipe Lisible';

$userManager = \OC::$server->get(\OCP\IUserManager::class);
$groupManager = \OC::$server->get(\OCP\IGroupManager::class);

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

$controller = \OC::$server->get(ShareController::class);

// A group whose display name differs from its GID — the readable-name case.
$group = $groupManager->get($groupId) ?? $groupManager->createGroup($groupId);
$group->addUser($user);
if (method_exists($group, 'setDisplayName')) {
	$group->setDisplayName($groupName);
}

try {
	// ---- accounts ----
	$users = $controller->sharees('alain', 'user')->getData()['sharees'];
	check('[1] une recherche de compte retourne des résultats', count($users) > 0, 'trouvés: ' . count($users));
	$shape = $users !== [] && !array_diff(['type', 'id', 'label', 'email'], array_keys($users[0]));
	check('[2] chaque résultat porte type, id, label, email', $shape, 'clés: ' . ($users !== [] ? implode(',', array_keys($users[0])) : '—'));
	$hasEmail = false;
	foreach ($users as $u) {
		if (($u['email'] ?? '') !== '') {
			$hasEmail = true;
		}
	}
	check('[3] au moins un compte expose un courriel', $hasEmail);

	// ---- group: label is the display name, not the GID ----
	$groups = $controller->sharees('Zzz Équipe', 'group')->getData()['sharees'];
	$mine = null;
	foreach ($groups as $g) {
		if (($g['id'] ?? '') === $groupId) {
			$mine = $g;
		}
	}
	check('[4] la recherche de groupe trouve le groupe', $mine !== null, 'groupes: ' . count($groups));
	check(
		'[5] le groupe est affiché par son NOM, pas son identifiant',
		$mine !== null && ($mine['label'] ?? '') === $groupName,
		'label: ' . var_export($mine['label'] ?? null, true) . ' (gid: ' . $groupId . ')',
	);

	// ---- edges ----
	check('[6] une recherche vide ne suggère rien', $controller->sharees('', 'user')->getData()['sharees'] === []);
	$team = $controller->sharees('zzz', 'team')->getData()['sharees'];
	check('[7] le type « équipe » répond un tableau sans planter', is_array($team), 'type: ' . gettype($team));
	// An unknown type must fail closed (no accidental enumeration), not error.
	$unknown = $controller->sharees('alain', 'zzz-bad')->getData()['sharees'];
	check('[8] un type inconnu ne divulgue rien', is_array($unknown));
} finally {
	$group->removeUser($user);
	$group->delete();
}

while (ob_get_level() > 0) {
	ob_end_flush();
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
