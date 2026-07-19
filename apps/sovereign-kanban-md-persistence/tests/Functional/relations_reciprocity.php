<?php

/**
 * @file
 * Functional (e2e) test: typed relations between cards round-trip through card.md
 * and always store a reciprocal on the other card (Alain, 2026-07-19).
 *
 * A --child--> B must write B --parent--> A; a besoin de (depends) ↔ nécessaire
 * pour (required); relié (related) is symmetric. The link stores only the target
 * id — the title is resolved live so a rename never goes stale. A relation MUST
 * survive an unrelated field edit (same carry-through rule as author).
 *
 * FALSIFICATION (this test can redden):
 *   - Drop the reciprocal write in FileCardRepository::addRelation → [reciprocal-*]
 *     go red.
 *   - Remove `relations: $card->relations` from update() reconstruction →
 *     [relations-survive-edit] goes red.
 *
 * Usage (as the web user, on the target container):
 *   runuser -u www-data -- php /tmp/relations_reciprocity.php
 * Exit codes: 0 = passed · 1 = failed · 70 = died and proved nothing · 2 = no account.
 *
 * SAFETY: one throwaway board zzz-e2e-relations under the test account, deleted
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
$BOARD = 'zzz-e2e-relations';

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
		printf("\e[32m✅\e[0m %-58s %s\n", $label, $detail);
	} else {
		$fail++;
		printf("\e[31m❌\e[0m %-58s %s\n", $label, $detail);
	}
}

/** The relation on $card pointing at $target, or null. */
function relationTo(array $detail, string $target): ?array {
	foreach ($detail['relations'] ?? [] as $r) {
		if (($r['card'] ?? null) === $target) {
			return $r;
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

	$a = $cardCtrl->create($boardId, 'Carte A', 'Backlog')->getData()['card']['id'];
	$b = $cardCtrl->create($boardId, 'Carte B', 'Backlog')->getData()['card']['id'];

	// --- child link stores parent reciprocal -------------------------------
	$res = $cardCtrl->addRelation($boardId, $a, 'child', $b);
	check('[add] linking A --child--> B returns 200', $res->getStatus() === 200, 'status ' . $res->getStatus());
	$detailA = $res->getData()['card'];
	$relAB = relationTo($detailA, $b);
	check('[a-has-child] A has a child relation to B', ($relAB['type'] ?? null) === 'child', json_encode($detailA['relations'] ?? null));
	check('[resolve-title] the relation carries B\'s title', ($relAB['title'] ?? null) === 'Carte B', var_export($relAB['title'] ?? null, true));

	$detailB = $cardCtrl->show($boardId, $b)->getData()['card'];
	$relBA = relationTo($detailB, $a);
	check('[reciprocal-parent] B has the reciprocal parent relation to A', ($relBA['type'] ?? null) === 'parent', json_encode($detailB['relations'] ?? null));

	// --- relations survive an unrelated edit (carry-through) ---------------
	$cardCtrl->update($boardId, $a, priority: '1');
	$detailA2 = $cardCtrl->show($boardId, $a)->getData()['card'];
	check('[relations-survive-edit] the link survives a field edit', relationTo($detailA2, $b) !== null, json_encode($detailA2['relations'] ?? null));

	// --- create-and-relate: new card in the same column, depends link ------
	$res = $cardCtrl->addRelation($boardId, $a, 'depends', null, 'Carte C nouvelle');
	check('[create-relate] create-and-relate returns 200', $res->getStatus() === 200, 'status ' . $res->getStatus());
	$detailA3 = $res->getData()['card'];
	$depends = array_values(array_filter($detailA3['relations'], static fn ($r) => ($r['type'] ?? '') === 'depends'));
	check('[create-relate-link] A now depends on a new card', count($depends) === 1, json_encode($detailA3['relations']));
	$c = $depends[0]['card'] ?? '';
	check('[create-relate-title] the new card carries the given title', ($depends[0]['title'] ?? null) === 'Carte C nouvelle', var_export($depends[0]['title'] ?? null, true));
	$detailC = $cardCtrl->show($boardId, $c)->getData()['card'];
	check('[create-relate-reciprocal] C is required-for A', (relationTo($detailC, $a)['type'] ?? null) === 'required', json_encode($detailC['relations'] ?? null));

	// --- guards ------------------------------------------------------------
	check('[guard-type] an unknown relation type is rejected', $cardCtrl->addRelation($boardId, $a, 'bogus', $b)->getStatus() === 400, '');
	check('[guard-self] a self-relation is rejected', $cardCtrl->addRelation($boardId, $a, 'related', $a)->getStatus() === 400, '');

	// --- remove clears both sides ------------------------------------------
	$res = $cardCtrl->removeRelation($boardId, $a, $b);
	check('[remove] removing the A–B link returns 200', $res->getStatus() === 200, 'status ' . $res->getStatus());
	check('[remove-a] A no longer links to B', relationTo($res->getData()['card'], $b) === null, json_encode($res->getData()['card']['relations']));
	$detailBafter = $cardCtrl->show($boardId, $b)->getData()['card'];
	check('[remove-reciprocal] B no longer links to A', relationTo($detailBafter, $a) === null, json_encode($detailBafter['relations'] ?? null));

	printf("\n%d passed, %d failed\n", $pass, $fail);
} finally {
	teardown($OWNER, $BOARD);
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
