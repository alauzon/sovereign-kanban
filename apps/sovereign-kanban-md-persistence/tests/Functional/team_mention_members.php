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
 * Ce test crée un cercle jetable (Test 1 propriétaire, Test 2 + un tiers
 * membres), partage un tableau jetable UNIQUEMENT via ce cercle, et vérifie que
 * members() liste toute l'équipe — pour le PROPRIÉTAIRE (le bug de Steve) ET
 * pour un INVITÉ (Alain, invité, ne voyait que Steve — 2026-07-25). Deux volets
 * du correctif, chacun rouge avant / vert après.
 *
 * PIÈGES CIRCLES (durement gagnés sur 211, 2026-07-25) :
 *  - addMember juste après createCircle échoue « Insufficient rights » tant que
 *    la propriété n'est pas confirmée → rouvrir la session en forceSync d'abord.
 *  - une session NC active (actAs) fausse la résolution de l'initiateur Circles
 *    pour les ops privilégiées (add/destroy) → monter le cercle AVANT tout actAs,
 *    et vider la session NC avant de détruire le cercle.
 *  - detruire un cercle puis en créer un dans le même process meurt (course
 *    d'événement fédéré) → nom unique par run, tout le destroy en toute fin.
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
// A THIRD circle member, neither owner nor the invitee under test — proves an
// invitee sees co-members, not just the board owner (Alain saw only Steve).
$THIRD = 'test@alainlauzon.com';
$BOARD = 'zzz-e2e-team-mentions';
$CIRCLE_PREFIX = 'zzz-e2e-team-mentions';
$CIRCLE_NAME = $CIRCLE_PREFIX . '-' . substr(md5(uniqid('', true)), 0, 8);

$server = \OC::$server;
$userManager = $server->get(\OCP\IUserManager::class);
$appManager = $server->get(\OCP\App\IAppManager::class);

foreach ([$OWNER, $RECIPIENT, $THIRD] as $uid) {
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

/** Drop the NC user session — Circles privileged ops need no stale initiator. */
function clearSession(): void {
	\OC::$server->get(\OCP\IUserSession::class)->setUser(null);
	\OC_User::setUserId(null);
	\OC_Util::tearDownFS();
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

/** Détruit tout cercle jetable (le nôtre + restes de runs morts) par PRÉFIXE.
 *  À n'appeler qu'en TOUTE FIN : destroy→exit, jamais destroy→create. */
function dropCircles(CirclesManager $cm, string $ownerUid, string $prefix): void {
	try {
		$fed = $cm->getFederatedUser($ownerUid, Member::TYPE_USER);
		$cm->startSession($fed);
		$probe = new CircleProbe();
		$probe->mustBeMember();
		foreach ($cm->getCircles($probe) as $c) {
			if (str_starts_with($c->getName(), $prefix)) {
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

// --- 1. cercle Test 1 (proprio) + Test 2 + tiers — AVANT tout actAs ---------

echo "\n[1] Setup cercle (avant toute session NC)\n";
$fedOwner = $circlesManager->getFederatedUser($OWNER, Member::TYPE_USER);
$circlesManager->startSession($fedOwner);
$circle = $circlesManager->createCircle($CIRCLE_NAME);
$circleId = $circle->getSingleId();
// forceSync : la propriété tout juste créée doit être confirmée avant addMember.
$circlesManager->startSession($fedOwner, true);
$circlesManager->addMember($circleId, $circlesManager->getFederatedUser($RECIPIENT, Member::TYPE_USER));
$circlesManager->addMember($circleId, $circlesManager->getFederatedUser($THIRD, Member::TYPE_USER));
echo "      cercle $CIRCLE_NAME ($circleId) : $OWNER proprio, $RECIPIENT + $THIRD membres\n";

// --- 2. tableau partagé SEULEMENT au cercle (avec session NC) ----------------

echo "[2] Setup tableau partagé au cercle (en tant que $OWNER)\n";
actAs($OWNER);
dropBoard($boardCtrl, $BOARD);
$r = $boardCtrl->create('zzz e2e team mentions', '#2e8b57');
check("le propriétaire crée le tableau ($BOARD) -> 201", status($r) === 201);
$shareService->share($BOARD, 'team', $circleId, 'collaborate');
echo "      $BOARD partagé au cercle (collaborate) — AUCUN partage direct\n";

// --- 3. côté PROPRIÉTAIRE : toute l'équipe est offerte à la mention ----------

echo "[3] members() du propriétaire (le bug de Steve)\n";
actAs($OWNER);
$uids = memberUids($cardCtrl, $BOARD);
check(
	'le propriétaire voit le membre du cercle',
	isset($uids[$RECIPIENT]),
	'obtenu: [' . implode(', ', array_keys($uids)) . ']'
);
check('le propriétaire voit aussi le tiers du cercle', isset($uids[$THIRD]));
check('le propriétaire ne se mentionne pas lui-même', !isset($uids[$OWNER]));

// --- 4. côté INVITÉ : toute l'équipe, pas seulement le propriétaire ----------
// (Alain, invité sur le tableau de Steve, ne voyait que Steve — 2026-07-25.)

echo "[4] members() de l'invité (voir un AUTRE membre que le propriétaire)\n";
actAs($RECIPIENT);
$uids = memberUids($cardCtrl, $BOARD);
check(
	"l'invité voit le propriétaire du tableau",
	isset($uids[$OWNER]),
	'obtenu: [' . implode(', ', array_keys($uids)) . ']'
);
check(
	"l'invité voit un AUTRE membre du cercle (pas que le propriétaire)",
	isset($uids[$THIRD]),
	'obtenu: [' . implode(', ', array_keys($uids)) . ']'
);
check('l\'invité ne se mentionne pas lui-même', !isset($uids[$RECIPIENT]));

// --- 5. teardown : tableau (session NC), puis cercles (session NC vidée) -----

echo "[5] Teardown\n";
actAs($OWNER);
dropBoard($boardCtrl, $BOARD);
clearSession();
dropCircles($circlesManager, $OWNER, $CIRCLE_PREFIX);

$completed = true;
echo "\nRésultat : $pass PASS · $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
