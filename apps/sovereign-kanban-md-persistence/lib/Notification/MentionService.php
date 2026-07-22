<?php

/**
 * @file
 * @mention parsing and notification for Sovereign Kanban (Alain, carte 78fc32).
 *
 * SK stores its own comments (comments.md), so it does NOT get Nextcloud's native
 * @mention parsing (that only fires for the oc_comments table). We parse the
 * tokens ourselves, resolve each to an account that ACTUALLY has access to the
 * board, and push a notification via IManager — the Deck model
 * (apps/deck/lib/Notification), reused here.
 *
 * Design note: notifyMentions returns the uids it notified, so the behaviour is
 * testable without inspecting the notification backend.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Notification;

use OCP\IUserManager;
use OCP\Notification\IManager;

final class MentionService {

	private const APP_ID = 'sovereign-kanban-md-persistence';

	public function __construct(
		private readonly IManager $notificationManager,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * Extract the @mention tokens from a text, de-duplicated, in order.
	 *
	 * A token is @ + [word chars, dot, dash], and ONLY when the @ starts the text
	 * or follows whitespace — so an email like « bob@example.org » is never read as
	 * a mention of « example ».
	 *
	 * @return list<string> The tokens, without the leading @.
	 */
	public function extractMentions(string $text): array {
		if (!preg_match_all('/(?<=^|\s)@([\w.\-]+)/u', $text, $m)) {
			return [];
		}
		$out = [];
		foreach ($m[1] as $token) {
			if (!in_array($token, $out, true)) {
				$out[] = $token;
			}
		}

		return $out;
	}

	/**
	 * Notify every @mentioned account that HAS access to the board.
	 *
	 * A token matches a candidate by uid or by display name (case-insensitive). The
	 * author is never notified of their own mention. Only accounts in
	 * $accessibleUids are eligible — a mention of someone without access notifies
	 * no one (never leak a card to a stranger).
	 *
	 * @param array<string,string> $accessibleUids uid => display name of everyone
	 *   who can see the board (owner + share recipients, groups/teams expanded).
	 *
	 * @return list<string> The uids actually notified.
	 */
	public function notifyMentions(
		string $boardId,
		string $cardId,
		string $cardTitle,
		string $text,
		string $authorUid,
		array $accessibleUids,
	): array {
		$tokens = $this->extractMentions($text);
		if ($tokens === []) {
			return [];
		}

		// Lower-cased lookup: uid → uid, and display name → uid.
		$byKey = [];
		foreach ($accessibleUids as $uid => $displayName) {
			$byKey[mb_strtolower((string) $uid)] = (string) $uid;
			if ($displayName !== '') {
				$byKey[mb_strtolower((string) $displayName)] = (string) $uid;
			}
		}

		$notified = [];
		foreach ($tokens as $token) {
			$uid = $byKey[mb_strtolower($token)] ?? null;
			if ($uid === null || $uid === $authorUid || in_array($uid, $notified, true)) {
				continue;
			}
			if ($this->userManager->get($uid) === null) {
				continue;
			}
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp(self::APP_ID)
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('card', $cardId)
				->setSubject('card_mention', [
					'author' => $authorUid,
					'title' => $cardTitle,
					'board' => $boardId,
				]);
			$this->notificationManager->notify($notification);
			$notified[] = $uid;
		}

		return $notified;
	}
}
