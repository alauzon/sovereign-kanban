<?php

/**
 * @file
 * Un tableau partagé par ÉQUIPE (Team/Cercle) expose ses membres aux mentions.
 *
 * Bug réel (Steve, 2026-07-25, board « developpement-technique » sur ET) : un
 * tableau dont le SEUL partage est un Team rendait une liste de membres vide au
 * propriétaire → le widget « Mentionner un membre » (v-if members.length) se
 * cachait silencieusement. accessibleUidsForBoard n'étendait que user+group ;
 * le type 'team' tombait dans le vide (« come later » du docblock).
 *
 * Ce test crée un cercle jetable (Test 1 propriétaire, Test 2 membre), partage
 * un tableau jetable UNIQUEMENT via ce cercle, et vérifie que members() du
 * propriétaire contient Test 2. Rouge avant le correctif, vert après.
 *
 * Nextcloud bufferise stdout en CLI et avale les fatals — flush pour voir.
 *
 * Usage: runuser -u www-data -- php team_mention_members.php
 *        0 ok · 1 échec · 2 setup · 70 mort sans rien prouver.
 */

while (ob_get_level() > 0) {
	ob_end_flush();
}
require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');
\OC_App::loadApp('circles');

use OCA\Circles\CirclesManager;
use OCA\Circles\Model\Member;
use OCA\Circles\Model\Probes\CircleProbe;
use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCP\AppFramework\Http\DataResponse;

// --- garde de terminaison anormale — NE PAS RETIRER -------------------------
// Une exception non rattrapée après OC_Util::setupFS() sort 0 (succès menteur).
$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ TERMINAISON ANORMALE\e[0m — le test est mort avant la fin. PAS un succès.\n");
		exit(70);
	}
});

$OWNER = 'Test 1';
$RECIPIENT = 'Test 2';
$BOARD = 'zzz-e2e-team-mentions';
$CIRCLE_NAME = 'zzz-e2e-team-mentions';

$server = \OC::$server;
$userManager = $server->get(\OCP\IUserManager::class);
$appManager = $server->get(\OCP\App\IAppManager::class);

foreach ([$OWNER, $RECIPIENT] as $uid) {
	if ($userManager->get($uid) === null) {
		fwrite(STDERR, "FATAL: compte '$uid' absent (lancer sur CT211)\n");
		$completed = true;
		exit(2);
	}
}
if (!$appManager->isEnabledForUser('circles')) {
	fwrite(STDERR, "FATAL: app circles désactivée\n");
	$completed = true;
	exit(2);
}

$pass = 0;
$fail = 0;

function actAs(string $uid): void {
	$u = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
	if ($u === null) {
		fwrite(STDERR, "FATAL: user '$uid' introuvable\n");
		exit(2);
	}
	\OC::$server->get(\OCP\IUserSession::class)->setUser($u);
	\OC_User::setUserId($uid);
	\OC_Util::tearDownFS();
	\OC_Util::setupFS($uid);
}

function check(string $label, bool $ok, string $detail = ''): void {
	global $pass, $fail;
	echo ($ok ? "  \e[32mPASS\e[0m  " : "  \e[31mFAIL\e[0m  ") . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
	$ok ? $pass++ : $fail++;
}

function status(mixed $r): int {
	return $r instanceof DataResponse ? $r->getStatus() : -1;
}

/** uid => displayName rendus par l'endpoint members, pour l'utilisateur courant. */
function memberUids(CardController $cardCtrl, string $boardId): array {
	$out = [];
	foreach ($cardCtrl->members($boardId)->getData()['members'] ?? [] as $m) {
		$out[$m['uid']] = $m['displayName'];
	}
	return $out;
}

function dropBoard(BoardController $boardCtrl, string $boardId): void {
	try {
		$boardCtrl->destroy($boardId);
	} catch (\Throwable) {
		// peut ne pas exister
	}
}

/** Détruit tout cercle jetable résiduel d'un run précédent (par NOM exact). */
function dropCircles(CirclesManager $cm, string $ownerUid, string $name): void {
	try {
		$fed = $cm->getFederatedUser($ownerUid, Member::TYPE_USER);
		$cm->startSession($fed);
		$probe = new CircleProbe();
		$probe->mustBeMember();
		foreach ($cm->getCircles($probe) as $c) {
			if ($c->getName() === $name) {
				$cm->destroyCircle($c->getSingleId());
			}
		}
	} catch (\Throwable) {
		// best-effort
	}
}

$boardCtrl = $server->get(BoardController::class);
$cardCtrl = $server->get(CardController::class);
$shareService = $server->get(BoardShareService::class);
$circlesManager = $server->get(CirclesManager::class);

// --- 0. nettoyer les restes d'un run précédent ------------------------------

echo "\n[0] Nettoyage des restes (en tant que $OWNER)\n";
actAs($OWNER);
dropBoard($boardCtrl, $BOARD);
dropCircles($circlesManager, $OWNER, $CIRCLE_NAME);

// --- 1. setup : cercle (Test 1 + Test 2) + tableau partagé SEULEMENT au cercle

echo "[1] Setup cercle + tableau (en tant que $OWNER)\n";
actAs($OWNER);

$fedOwner = $circlesManager->getFederatedUser($OWNER, Member::TYPE_USER);
$circlesManager->startSession($fedOwner);
$circle = $circlesManager->createCircle($CIRCLE_NAME);
$circleId = $circle->getSingleId();
$fedRecipient = $circlesManager->getFederatedUser($RECIPIENT, Member::TYPE_USER);
$circlesManager->addMember($circleId, $fedRecipient);
echo "      cercle $CIRCLE_NAME ($circleId) : $OWNER propriétaire, $RECIPIENT membre\n";

$r = $boardCtrl->create('zzz e2e team mentions', '#2e8b57');
check("le propriétaire crée le tableau ($BOARD) -> 201", status($r) === 201);

$shareService->share($BOARD, 'team', $circleId, 'collaborate');
echo "      $BOARD partagé au cercle (collaborate) — AUCUN partage direct\n";

// --- 2. la liste de mention du propriétaire contient les membres du cercle --

echo "[2] members() du propriétaire (le bug de Steve)\n";
actAs($OWNER);
$uids = memberUids($cardCtrl, $BOARD);
check(
	'le membre du cercle est offert à la mention (le RED attendu avant correctif)',
	isset($uids[$RECIPIENT]),
	'obtenu: [' . implode(', ', array_keys($uids)) . ']'
);
check('le propriétaire ne se mentionne pas lui-même', !isset($uids[$OWNER]));

// --- 3. non-régression : l'invité (via cercle) résout au moins le propriétaire

echo "[3] members() de l'invité (repli existant — doit rester vert)\n";
actAs($RECIPIENT);
$uids = memberUids($cardCtrl, $BOARD);
check(
	"l'invité via cercle voit au moins le propriétaire",
	isset($uids[$OWNER]),
	'obtenu: [' . implode(', ', array_keys($uids)) . ']'
);

// --- 4. teardown ------------------------------------------------------------

echo "[4] Teardown\n";
actAs($OWNER);
dropBoard($boardCtrl, $BOARD);
dropCircles($circlesManager, $OWNER, $CIRCLE_NAME);

$completed = true;
echo "\nRésultat : $pass PASS · $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
