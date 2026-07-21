<?php

/**
 * @file
 * A board's identity must live in .board.yml, not in its folder name.
 *
 * Alain, 2026-07-20, on ET: his own board « Bienvenue » and Steve's board
 * « Développement Kanban souverain » BOTH answer to the id « bienvenue » —
 * Steve created his as « Bienvenue » then renamed it, and withName() keeps the
 * slug so the folder never moves. Clicking the shared board showed Alain's own
 * one instead, and both entries lit up in the sidebar.
 *
 * Option B, chosen by Alain: boards get an identifier of their own. Option 2
 * within B, also his: the FOLDER stays readable (that is the whole point of this
 * vault) and the unique id lives in .board.yml. So resolution must go through the
 * file's content, never through the directory name.
 *
 * RED while find() reads <id>/.board.yml, GREEN once it resolves by the id
 * recorded inside each board's .board.yml.
 *
 * Usage: runuser -u www-data -- php board_identity.php   ·   0 ok · 1 fail · 2 setup.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;
use Symfony\Component\Yaml\Yaml;

$uid = 'Alain Lauzon';
$user = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
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

$root = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$kanban = $root->nodeExists('Kanban') ? $root->get('Kanban') : $root->newFolder('Kanban');
$repo = new FileBoardRepository(new NextcloudStorage($kanban));

// A board whose folder is readable and whose identity is something else — the
// shape every board takes once B lands.
$folder = 'zzz-dossier-lisible';
$identity = 'b7f3c1de-0000-4000-8000-zzzidentity';
$dir = $kanban->newFolder($folder);
$dir->newFile('.board.yml', Yaml::dump([
	'id' => $identity,
	'name' => 'zzz Identité',
	'color' => '#0082c9',
	'columns' => ['À faire', 'Terminé'],
	'tags' => [],
]));

try {
	$found = $repo->find($identity);
	check(
		'[1] find() résout un tableau par l’id de son .board.yml',
		$found !== null,
		'dossier « ' . $folder .' », id « ' . $identity . ' »',
	);
	check(
		'[2] et rend bien ce tableau-là',
		$found !== null && $found->name === 'zzz Identité',
		'nom obtenu: ' . var_export($found?->name, true),
	);
	check(
		'[3] le nom du dossier n’est PAS un identifiant valide',
		$repo->find($folder) === null,
		'find("' . $folder . '") devrait être null',
	);
	$ids = array_map(static fn ($b) => $b->id, $repo->list());
	check(
		'[4] list() annonce l’id du fichier, pas celui du dossier',
		in_array($identity, $ids, true) && !in_array($folder, $ids, true),
	);
} finally {
	$dir->delete();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
