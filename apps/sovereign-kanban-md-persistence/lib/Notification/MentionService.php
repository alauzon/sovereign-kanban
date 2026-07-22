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
	 * The author is never notified of their own mention. Only accounts in
	 * $accessibleUids are eligible — a mention of someone without access notifies
	 * no one (never leak a card to a stranger).
	 *
	 * @param array<string,string> $accessibleUids uid => display name of everyone
	 *   who can see the board (owner + share recipients, groups/teams expanded).
	 * @param string $tab The card tab the notification link should open — 'comments'
	 *   for a comment mention, 'details' for a description mention (Alain, 2026-07-22).
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
		string $tab = 'comments',
	): array {
		$uids = $this->mentionedUids($text, $authorUid, $accessibleUids);
		foreach ($uids as $uid) {
			$this->pushCardNotification($uid, $boardId, $cardId, $cardTitle, $authorUid, 'card_mention', ['tab' => $tab]);
		}

		return $uids;
	}

	/**
	 * Notify only mentions ADDED since $previousText — so re-saving a card does not
	 * re-ping everyone already named in it (Alain, 2026-07-22, the description path).
	 *
	 * @param array<string,string> $accessibleUids uid => display name.
	 *
	 * @return list<string> The uids newly notified.
	 */
	public function notifyNewMentions(
		string $boardId,
		string $cardId,
		string $cardTitle,
		string $newText,
		string $previousText,
		string $authorUid,
		array $accessibleUids,
		string $tab = 'comments',
	): array {
		$before = $this->mentionedUids($previousText, $authorUid, $accessibleUids);
		$added = array_values(array_diff($this->mentionedUids($newText, $authorUid, $accessibleUids), $before));
		foreach ($added as $uid) {
			$this->pushCardNotification($uid, $boardId, $cardId, $cardTitle, $authorUid, 'card_mention', ['tab' => $tab]);
		}

		return $added;
	}

	/**
	 * Notify accounts newly assigned to a card — « X vous a assigné la carte Y », the
	 * same clickable mechanism as a @mention (Alain, 2026-07-22). Only accounts with
	 * board access are notified; the author is never notified of a self-assignment.
	 *
	 * @param list<string> $addedUids The uids added to the card's assignees this edit.
	 * @param array<string,string> $accessibleUids uid => display name.
	 *
	 * @return list<string> The uids notified.
	 */
	public function notifyAssignees(
		string $boardId,
		string $cardId,
		string $cardTitle,
		array $addedUids,
		string $authorUid,
		array $accessibleUids,
	): array {
		$notified = [];
		foreach ($addedUids as $uid) {
			$uid = (string) $uid;
			if ($uid === $authorUid || !isset($accessibleUids[$uid]) || in_array($uid, $notified, true)) {
				continue;
			}
			if ($this->userManager->get($uid) === null) {
				continue;
			}
			$this->pushCardNotification($uid, $boardId, $cardId, $cardTitle, $authorUid, 'card_assigned', []);
			$notified[] = $uid;
		}

		return $notified;
	}

	/**
	 * The uids among $accessibleUids that $text @mentions (never the author).
	 *
	 * Matches each accessible member by « @uid » OR « @Display Name » — searching per
	 * member (not tokenizing) is what lets a name WITH SPACES like « @Alain Lauzon »
	 * be caught. Longest needle first so « @Alain Lauzon » wins over a bare « @Alain ».
	 *
	 * @param array<string,string> $accessibleUids uid => display name.
	 *
	 * @return list<string>
	 */
	public function mentionedUids(string $text, string $authorUid, array $accessibleUids): array {
		if (!str_contains($text, '@')) {
			return [];
		}
		$out = [];
		foreach ($accessibleUids as $uid => $displayName) {
			$uid = (string) $uid;
			if ($uid === $authorUid || in_array($uid, $out, true)) {
				continue;
			}
			if ($this->isMentioned($text, $uid, (string) $displayName) && $this->userManager->get($uid) !== null) {
				$out[] = $uid;
			}
		}

		// Also recognize the canonical Nextcloud Text mention markdown, produced when
		// a mention is inserted as a rich node: @[Label](mention://user/<uid>), the uid
		// URI-encoded (Alain, 2026-07-22, from the Text editor contract). The plain
		// forms above miss it entirely, so a node mention would notify no one.
		if (preg_match_all('#@\[[^\]]*\]\(mention://user/([^)]+)\)#u', $text, $mm)) {
			foreach ($mm[1] as $encoded) {
				$uid = rawurldecode($encoded);
				if ($uid === $authorUid || in_array($uid, $out, true) || !isset($accessibleUids[$uid])) {
					continue;
				}
				if ($this->userManager->get($uid) !== null) {
					$out[] = $uid;
				}
			}
		}

		return $out;
	}

	/**
	 * Whether $text @mentions the member (by display name or uid). Two accepted forms:
	 * @"Alain Lauzon" (quoted, façon Talk — the safe one for names with spaces) and
	 * bare @Alain Lauzon. Bounded left by start/space and right by end/space/punctuation,
	 * so « bob@x.org » is inert (Alain, 2026-07-22).
	 */
	private function isMentioned(string $text, string $uid, string $displayName): bool {
		$needles = array_unique(array_filter([$displayName, $uid]));
		usort($needles, static fn (string $a, string $b): int => mb_strlen($b) - mb_strlen($a));
		foreach ($needles as $needle) {
			$q = preg_quote($needle, '/');
			if (preg_match('/(?<=^|\s)@(?:"' . $q . '"|' . $q . '(?=$|\s|[.,;:!?]))/u', $text) === 1) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create and dispatch one card notification to a user.
	 *
	 * @param array<string,string> $extraParams Extra subject parameters (e.g. tab).
	 */
	private function pushCardNotification(
		string $uid,
		string $boardId,
		string $cardId,
		string $cardTitle,
		string $authorUid,
		string $subject,
		array $extraParams,
	): void {
		$notification = $this->notificationManager->createNotification();
		$notification
			->setApp(self::APP_ID)
			->setUser($uid)
			->setDateTime(new \DateTime())
			->setObject('card', $cardId)
			->setSubject($subject, array_merge([
				'author' => $authorUid,
				'title' => $cardTitle,
				'board' => $boardId,
			], $extraParams));
		$this->notificationManager->notify($notification);
	}
}
