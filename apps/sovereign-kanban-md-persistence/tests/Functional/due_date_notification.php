<?php

/**
 * @file
 * Functional test: the due-date job notifies when a card's due moment falls in
 * the (lastRun, now] window, and NOT for completed/archived/future cards (Alain,
 * 2026-07-19). Scoped to Test 1 via scanUser() — never run() — so it can't
 * notify real members on a shared instance.
 *
 * FALSIFICATION: widen the guard (e.g. drop the completed_at check in
 * maybeNotify) → [skip-done] goes red.
 *
 * Usage: runuser -u www-data -- php /tmp/due_date_notification.php
 * Exit codes: 0 passed · 1 failed · 70 died · 2 no account.
 *
 * SAFETY: board zzz-e2e-due under Test 1; notifications it raises are deleted.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

require_once '/var/www/nextcloud/lib/base.php';

// notify() only reaches the DB once the Notifications app is booted — in a CLI
// script it is not (the real job runs in cron, where it is). Boot it here so the
// test exercises the same storage path as production.
\OC_App::loadApp('notifications');

use OCA\SovereignKanbanMdPersistence\BackgroundJob\DueDateJob;
use OCA\SovereignKanbanMdPersistence\Controller\BoardController;
use OCA\SovereignKanbanMdPersistence\Controller\CardController;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ReceivedBoardLocator;

$completed = false;
register_shutdown_function(function () use (&$completed) {
	if (!$completed) {
		fwrite(STDERR, "\n\e[31m⛔ ABNORMAL TERMINATION\e[0m — NOT a pass.\n");
		exit(70);
	}
});

$OWNER = 'Test 1';
$BOARD = 'zzz-e2e-due';

$server = \OC::$server;
$userSession = $server->get(\OCP\IUserSession::class);
$rootFolder = $server->get(\OCP\Files\IRootFolder::class);
$shareManager = $server->get(\OCP\Share\IManager::class);
$request = $server->get(\OCP\IRequest::class);
$db = $server->get(\OCP\IDBConnection::class);

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

function check(string $l, bool $ok, string $d = ''): void {
	global $pass, $fail;
	$pass += $ok ? 1 : 0;
	$fail += $ok ? 0 : 1;
	printf("%s %-50s %s\n", $ok ? "\e[32m✅\e[0m" : "\e[31m❌\e[0m", $l, $d);
}

/** Count due notifications for a card id. */
function notifCount(\OCP\IDBConnection $db, string $cardId): int {
	$q = $db->getQueryBuilder();
	$q->select($q->func()->count('*', 'c'))
		->from('notifications')
		->where($q->expr()->eq('app', $q->createNamedParameter('sovereign-kanban-md-persistence')))
		->andWhere($q->expr()->eq('subject', $q->createNamedParameter('card_due')))
		->andWhere($q->expr()->eq('object_id', $q->createNamedParameter($cardId)));
	return (int) $q->executeQuery()->fetchOne();
}

function deleteNotifs(\OCP\IDBConnection $db, string $cardId): void {
	$q = $db->getQueryBuilder();
	$q->delete('notifications')
		->where($q->expr()->eq('app', $q->createNamedParameter('sovereign-kanban-md-persistence')))
		->andWhere($q->expr()->eq('object_id', $q->createNamedParameter($cardId)));
	$q->executeStatement();
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

$ids = [];
try {
	$boardId = $boardCtrl->create($BOARD, '#0082c9')->getData()['board']['id'] ?? $BOARD;

	$yesterday = gmdate('Y-m-d', time() - 86400);
	$tomorrow = gmdate('Y-m-d', time() + 86400);

	// A: due yesterday, active → must notify.
	$a = $cardCtrl->create($boardId, 'Échéance atteinte', 'Backlog')->getData()['card']['id'];
	$cardCtrl->update($boardId, $a, due_date: $yesterday);
	// B: due yesterday, completed → must NOT notify.
	$b = $cardCtrl->create($boardId, 'Échéance mais faite', 'Backlog')->getData()['card']['id'];
	$cardCtrl->update($boardId, $b, due_date: $yesterday, completed_at: '2026-07-19T00:00:00Z');
	// C: due tomorrow → not yet → must NOT notify.
	$c = $cardCtrl->create($boardId, 'Échéance future', 'Backlog')->getData()['card']['id'];
	$cardCtrl->update($boardId, $c, due_date: $tomorrow);
	$ids = [$a, $b, $c];

	// Run the scan scoped to Test 1 only, window = (2 days ago, now].
	$job = new DueDateJob(
		$server->get(\OCP\AppFramework\Utility\ITimeFactory::class),
		$server->get(\OCP\IUserManager::class),
		$rootFolder,
		$server->get(\OCP\Notification\IManager::class),
		$server->get(\OCP\IConfig::class),
	);
	$scan = new ReflectionMethod(DueDateJob::class, 'scanUser');
	$scan->setAccessible(true);
	$scan->invoke($job, $OWNER, time() - 2 * 86400, time());

	check('[notify-due] an overdue active card notifies', notifCount($db, $a) >= 1, 'count ' . notifCount($db, $a));
	check('[skip-done] a completed card does not notify', notifCount($db, $b) === 0, 'count ' . notifCount($db, $b));
	check('[skip-future] a future card does not notify', notifCount($db, $c) === 0, 'count ' . notifCount($db, $c));

	printf("\n%d passed, %d failed\n", $pass, $fail);
} catch (\Throwable $e) {
	fwrite(STDOUT, 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
	$fail++;
} finally {
	foreach ($ids as $id) {
		deleteNotifs($db, $id);
	}
	teardown($OWNER, $BOARD);
	$completed = true;
}

exit($fail === 0 ? 0 : 1);
