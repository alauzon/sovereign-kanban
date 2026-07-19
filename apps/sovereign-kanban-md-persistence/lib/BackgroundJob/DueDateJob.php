<?php

/**
 * @file
 * Hourly job (Alain, 2026-07-19): notify when a card reaches its due date, façon
 * Deck. Windowed on the last run — a card is notified once, when its due moment
 * falls between the previous run and now — so no per-card marker is stored (the
 * sovereign file stays clean) and no card is notified twice.
 *
 * Scans the boards each seen user OWNS. Everything goes through NextcloudStorage,
 * so it also reads correctly on an encrypted instance (ET).
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\BackgroundJob;

use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager;

final class DueDateJob extends TimedJob {

	private const APP_ID = 'sovereign-kanban-md-persistence';
	private const LAST_RUN_KEY = 'due_last_run';

	private readonly ITimeFactory $timeFactory;

	public function __construct(
		ITimeFactory $time,
		private readonly IUserManager $userManager,
		private readonly IRootFolder $rootFolder,
		private readonly IManager $notificationManager,
		private readonly IConfig $config,
	) {
		parent::__construct($time);
		$this->timeFactory = $time;
		$this->setInterval(3600);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$now = $this->timeFactory->getTime();
		$lastRun = (int) $this->config->getAppValue(self::APP_ID, self::LAST_RUN_KEY, '0');
		// First ever run: start the window now, notify nothing retroactively.
		if ($lastRun === 0) {
			$this->config->setAppValue(self::APP_ID, self::LAST_RUN_KEY, (string) $now);
			return;
		}

		$this->userManager->callForSeenUsers(function ($user) use ($lastRun, $now): void {
			$this->scanUser($user->getUID(), $lastRun, $now);
		});

		$this->config->setAppValue(self::APP_ID, self::LAST_RUN_KEY, (string) $now);
	}

	/**
	 * Scan one user's owned boards for cards whose due moment fell in (last, now].
	 */
	private function scanUser(string $uid, int $lastRun, int $now): void {
		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			if (!$userFolder->nodeExists('Kanban')) {
				return;
			}
			$kanban = $userFolder->get('Kanban');
			if (!($kanban instanceof Folder)) {
				return;
			}
		} catch (\Throwable $e) {
			return;
		}

		$boardRepo = new FileBoardRepository(new NextcloudStorage($kanban));
		foreach ($boardRepo->list() as $board) {
			try {
				$boardFolder = $kanban->get($board->id);
				if (!($boardFolder instanceof Folder)) {
					continue;
				}
				$cardRepo = new FileCardRepository(new NextcloudStorage($boardFolder));
				foreach ($cardRepo->listByColumn() as $cards) {
					foreach ($cards as $card) {
						$this->maybeNotify($card, $board->name, $uid, $lastRun, $now);
					}
				}
			} catch (\Throwable $e) {
				// One bad board must not stop the others.
				continue;
			}
		}
	}

	/**
	 * Notify the card's assignees (or the owner) if its due moment is in (last, now].
	 */
	private function maybeNotify(Card $card, string $boardName, string $ownerUid, int $lastRun, int $now): void {
		if ($card->due_date === null || $card->completed_at !== null || $card->archived !== null) {
			return;
		}
		$dueTs = strtotime($card->due_date);
		if ($dueTs === false || $dueTs <= $lastRun || $dueTs > $now) {
			return;
		}

		$targets = $card->assignees !== [] ? $card->assignees : [$ownerUid];
		foreach (array_unique($targets) as $target) {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp(self::APP_ID)
				->setUser((string) $target)
				->setDateTime((new \DateTime())->setTimestamp($now))
				->setObject('card', $card->id)
				->setSubject('card_due', ['title' => $card->title, 'board' => $boardName]);
			$this->notificationManager->notify($notification);
		}
	}
}
