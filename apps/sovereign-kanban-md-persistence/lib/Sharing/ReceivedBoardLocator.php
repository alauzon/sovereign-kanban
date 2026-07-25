<?php

/**
 * @file
 * Locates the folder of a board shared TO the current user, by board id.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Sharing;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves the folder of a received board by its id.
 *
 * A received share lands at the invitee's Files ROOT, not under Kanban/ (spec
 * §12), so the card/board controllers can't find it by path. This walks the
 * user/group/team shares received by the current user and returns the shared
 * folder whose .board.yml id matches — so a received board becomes navigable.
 * NC-specific; validated on staging.
 */
final class ReceivedBoardLocator {

	private const TYPES = [IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_CIRCLE];

	/** NC share type → our principal-type name (matches the owner-side listShares). */
	private const TYPE_NAME = [
		IShare::TYPE_USER => 'user',
		IShare::TYPE_GROUP => 'group',
		IShare::TYPE_CIRCLE => 'team',
	];

	public function __construct(
		private readonly IManager $shareManager,
		private readonly IUserSession $userSession,
	) {
	}

	/**
	 * The shared folder of the received board with this id, or null.
	 */
	public function folderFor(string $boardId): ?Folder {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		foreach (self::TYPES as $type) {
			foreach ($this->shareManager->getSharedWith($user->getUID(), $type, null, 500) as $share) {
				try {
					$node = $share->getNode();
				} catch (NotFoundException) {
					// Stale share whose target no longer exists (same guard as
					// NextcloudShareGateway::receivedBoards, incident 2026-07-09).
					continue;
				}
				if (!$node instanceof Folder || !$node->nodeExists('.board.yml')) {
					continue;
				}
				$file = $node->get('.board.yml');
				$data = $file instanceof File ? (Yaml::parse($file->getContent()) ?? []) : [];
				if ((string) ($data['id'] ?? $node->getName()) === $boardId) {
					return $node;
				}
			}
		}

		return null;
	}

	/**
	 * The principals (channels) through which the current user received a board.
	 *
	 * An invitee cannot call the owner-only listShares, so the mention picker
	 * had no way to learn the board's co-members and showed only the owner
	 * (Alain, an invitee, saw just Steve — 2026-07-25). These are the shares the
	 * invitee legitimately sees: their own user share, and every group/team they
	 * belong to that the board reached. Expanding them (owner-side does the same)
	 * yields the whole team, never a direct share to a third party the invitee
	 * has no visibility into — the correct privacy boundary.
	 *
	 * @return list<array{type: string, with: string, owner: string}> type is
	 *   'user'|'group'|'team'; owner is the account that shared the board out.
	 */
	public function receivedPrincipals(string $boardId): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$out = [];
		foreach (self::TYPES as $type) {
			foreach ($this->shareManager->getSharedWith($user->getUID(), $type, null, 500) as $share) {
				// A board I own, shared to a group I'm in, returns here too — it is
				// not « received ». Ownership excludes it (same guard as the gateway).
				if ($share->getShareOwner() === $user->getUID()) {
					continue;
				}
				try {
					$node = $share->getNode();
				} catch (NotFoundException) {
					continue;
				}
				if (!$node instanceof Folder || !$node->nodeExists('.board.yml')) {
					continue;
				}
				$file = $node->get('.board.yml');
				$data = $file instanceof File ? (Yaml::parse($file->getContent()) ?? []) : [];
				if ((string) ($data['id'] ?? $node->getName()) !== $boardId) {
					continue;
				}
				$out[] = [
					'type' => self::TYPE_NAME[$type],
					'with' => (string) $share->getSharedWith(),
					'owner' => (string) $share->getSharedBy(),
				];
			}
		}

		return $out;
	}
}
