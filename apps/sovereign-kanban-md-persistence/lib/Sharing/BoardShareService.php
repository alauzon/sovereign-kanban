<?php

/**
 * @file
 * Board sharing business logic, decoupled from Nextcloud via ShareGateway.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Sharing;

/**
 * Orchestrates board sharing over a ShareGateway port.
 *
 * All the policy lives here — owner-only guard, level → permission mapping —
 * so it is unit-tested against a fake gateway. The Nextcloud specifics (real
 * IManager calls, folder mount point) live in the adapter and are covered on
 * staging.
 */
final class BoardShareService {

	private const SHARE_TYPES = ['user', 'group', 'team'];

	public function __construct(
		private readonly ShareGateway $gateway,
	) {
	}

	/**
	 * Share a board with a recipient at a given level.
	 *
	 * @param string $boardId Board slug.
	 * @param string $shareType 'user' | 'group' | 'team'.
	 * @param string $shareWith Recipient id.
	 * @param string $level 'read' or 'collaborate' (see SharePermissions).
	 *
	 * @return string The created share id.
	 *
	 * @throws NotBoardOwnerException If the current user does not own the board.
	 * @throws \InvalidArgumentException If the level is unknown.
	 */
	public function share(string $boardId, string $shareType, string $shareWith, string $level): string {
		if (!$this->gateway->currentUserOwns($boardId)) {
			throw new NotBoardOwnerException($boardId);
		}
		if (!in_array($shareType, self::SHARE_TYPES, true)) {
			throw new \InvalidArgumentException('Unknown share type: ' . $shareType);
		}

		$permissions = SharePermissions::forLevel($level);

		return $this->gateway->share($boardId, $shareType, $shareWith, $permissions);
	}

	/**
	 * Revoke a share from a board.
	 *
	 * Owner-only, and the share must actually belong to that board — so a board
	 * owner cannot revoke someone else's share just by knowing its id.
	 *
	 * @throws NotBoardOwnerException If the current user does not own the board.
	 * @throws ShareNotOnBoardException If the share id is not one of the board's.
	 */
	public function revoke(string $boardId, string $shareId): void {
		if (!$this->gateway->currentUserOwns($boardId)) {
			throw new NotBoardOwnerException($boardId);
		}
		$ids = array_column($this->gateway->listShares($boardId), 'id');
		if (!in_array($shareId, $ids, true)) {
			throw new ShareNotOnBoardException($boardId, $shareId);
		}

		$this->gateway->revoke($shareId);
	}

	/**
	 * List a board's shares — owner-only.
	 *
	 * @return list<array{id: string, type: string, with: string, permissions: int}>
	 *
	 * @throws NotBoardOwnerException If the current user does not own the board.
	 */
	public function listShares(string $boardId): array {
		if (!$this->gateway->currentUserOwns($boardId)) {
			throw new NotBoardOwnerException($boardId);
		}

		return $this->gateway->listShares($boardId);
	}

	/**
	 * Boards shared to the current user, deduplicated by id.
	 *
	 * No owner check — these are the user's own received shares. Duplicates
	 * (the same board reaching them directly and via a group) are collapsed.
	 *
	 * @return list<array{id: string, name: string, owner: string, permissions: int}>
	 */
	public function receivedBoards(): array {
		$seen = [];
		$out = [];
		foreach ($this->gateway->receivedBoards() as $board) {
			if (isset($seen[$board['id']])) {
				continue;
			}
			$seen[$board['id']] = true;
			$out[] = $board;
		}

		return $out;
	}
}
