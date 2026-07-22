<?php

/**
 * @file
 * @mention parsing and access-scoped notification (carte 78fc32).
 *
 * A comment « @StevLauz coucou » on a board StevLauz can see notifies him; a
 * mention of someone WITHOUT access, or of the author himself, notifies no one.
 * SK has its own comments, so this logic is ours — this pins it.
 *
 * Runs on CT204 (ET), where « Alain Lauzon » and « StevLauz » both exist.
 * Notifications land for real, then are cleaned up with markProcessed.
 *
 * Nextcloud buffers stdout in CLI and swallows fatals — flush so results show.
 *
 * Usage: runuser -u www-data -- php mention_notify.php   ·   0 ok · 1 fail · 2 setup.
 */

while (ob_get_level() > 0) {
	ob_end_flush();
}
require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Notification\MentionService;

$author = 'Alain Lauzon';
$target = 'StevLauz';
$userManager = \OC::$server->get(\OCP\IUserManager::class);
if ($userManager->get($author) === null || $userManager->get($target) === null) {
	fwrite(STDERR, "FATAL: comptes de test absents (lancer sur CT204)\n");
	exit(2);
}
$manager = \OC::$server->get(\OCP\Notification\IManager::class);
$service = new MentionService($manager, $userManager);

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
	global $pass, $fail;
	echo ($ok ? "\e[32m✅\e[0m " : "\e[31m❌\e[0m ") . $label . ($detail !== '' ? "  $detail" : '') . "\n";
	$ok ? $pass++ : $fail++;
}

$testCard = 'zzz-mention-test-card';
$access = [$author => 'Alain Lauzon', $target => 'Steve Lauzier'];

try {
	// ---- extractMentions (pure) ----
	$m = $service->extractMentions('@StevLauz coucou @alain, écris à bob@example.org stp @@x @StevLauz');
	check('[1] extrait les tokens @, dédupliqués', $m === ['StevLauz', 'alain'], 'obtenu: [' . implode(', ', $m) . ']');
	check('[2] un courriel bob@example.org n’est PAS une mention', !in_array('example', $m, true));

	// ---- notify: mentioned + has access ----
	$n1 = $service->notifyMentions('board1', $testCard, 'Ma carte', '@StevLauz regarde ça', $author, $access);
	check('[3] un mentionné qui a accès est notifié', $n1 === [$target], 'notifiés: [' . implode(', ', $n1) . ']');

	// ---- notify: a name WITH A SPACE (« Alain Lauzon » is uid AND display name) ----
	$nb = $service->notifyMentions('board1', $testCard, 'Ma carte', 'salut @Steve Lauzier ça va', $author, $access);
	check('[3b] un nom avec espace (@Steve Lauzier) est reconnu', $nb === [$target], 'notifiés: [' . implode(', ', $nb) . ']');

	// ---- notify: the quoted form @"..." ----
	$nc = $service->notifyMentions('board1', $testCard, 'Ma carte', 'salut @"Steve Lauzier" !', $author, $access);
	check('[3c] la forme entre guillemets @"…" est reconnue', $nc === [$target], 'notifiés: [' . implode(', ', $nc) . ']');

	// ---- notify: mentioned but NO access ----
	$n2 = $service->notifyMentions('board1', $testCard, 'Ma carte', '@personne-inconnue hello', $author, $access);
	check('[4] un mentionné SANS accès n’est notifié de rien', $n2 === []);

	// ---- notify: the author mentions himself ----
	$n3 = $service->notifyMentions('board1', $testCard, 'Ma carte', '@StevLauz coucou', $target, $access);
	check('[5] l’auteur n’est pas notifié de sa propre mention', $n3 === []);

	// ---- description delta: notify only mentions ADDED since the previous text ----
	$d1 = $service->notifyNewMentions('board1', $testCard, 'Ma carte', 'desc @StevLauz', '', $author, $access, 'details');
	check('[6] une mention ajoutée à la description notifie', $d1 === [$target], 'notifiés: [' . implode(', ', $d1) . ']');
	$d2 = $service->notifyNewMentions('board1', $testCard, 'Ma carte', 'desc @StevLauz encore', 'desc @StevLauz', $author, $access, 'details');
	check('[7] une mention DÉJÀ présente ne re-notifie pas', $d2 === [], 'notifiés: [' . implode(', ', $d2) . ']');

	// ---- assignment: notify a newly-assigned member with access ----
	$a1 = $service->notifyAssignees('board1', $testCard, 'Ma carte', [$target], $author, $access);
	check('[8] un membre nouvellement assigné est notifié', $a1 === [$target], 'notifiés: [' . implode(', ', $a1) . ']');
	$a2 = $service->notifyAssignees('board1', $testCard, 'Ma carte', [$author], $author, $access);
	check('[9] s’assigner soi-même ne notifie pas', $a2 === []);
	$a3 = $service->notifyAssignees('board1', $testCard, 'Ma carte', ['personne-sans-acces'], $author, $access);
	check('[10] assigner quelqu’un SANS accès ne notifie de rien', $a3 === []);
} finally {
	// Remove the real notifications this test pushed to StevLauz.
	$cleanup = $manager->createNotification();
	$cleanup->setApp('sovereign-kanban-md-persistence')->setObject('card', $testCard);
	$manager->markProcessed($cleanup);
}

while (ob_get_level() > 0) {
	ob_end_flush();
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
