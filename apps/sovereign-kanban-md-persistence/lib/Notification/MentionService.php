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
		if (!str_contains($text, '@')) {
			return [];
		}

		// Match each accessible member by « @uid » OR « @Display Name » — searching
		// per member (not tokenizing) is what lets a name WITH SPACES like
		// « @Alain Lauzon » be caught (Alain, 2026-07-22). Longest needle first so
		// « @Alain Lauzon » wins over a bare « @Alain ». Bounded by start/space on
		// the left and end/space/punctuation on the right, so « bob@x.org » is inert.
		$notified = [];
		foreach ($accessibleUids as $uid => $displayName) {
			$uid = (string) $uid;
			if ($uid === $authorUid || in_array($uid, $notified, true)) {
				continue;
			}
			$needles = array_unique(array_filter([(string) $displayName, $uid]));
			usort($needles, static fn (string $a, string $b): int => mb_strlen($b) - mb_strlen($a));
			$hit = false;
			foreach ($needles as $needle) {
				// Two accepted forms: @"Alain Lauzon" (quoted, façon Talk — the safe
				// one for names with spaces) and bare @Alain Lauzon (Alain, 2026-07-22).
				$q = preg_quote($needle, '/');
				if (preg_match('/(?<=^|\s)@(?:"' . $q . '"|' . $q . '(?=$|\s|[.,;:!?]))/u', $text) === 1) {
					$hit = true;
					break;
				}
			}
			if (!$hit || $this->userManager->get($uid) === null) {
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
