<?php

/**
 * @file
 * Port: the board-sharing capability the service needs, decoupled from Nextcloud.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Sharing;

/**
 * A narrow port over the sharing backend.
 *
 * BoardShareService depends on this interface (mockable in the unit suite), not
 * on OCP\Share\IManager directly. The concrete NextcloudShareGateway (Lot 2,
 * integration) adapts it onto IManager and is validated on staging — never in
 * the unit suite, since OCP interfaces aren't loadable outside a real Nextcloud.
 */
interface ShareGateway {

	/**
	 * Does the current user own the board folder? Sharing is owner-only.
	 */
	public function currentUserOwns(string $boardId): bool;

	/**
	 * Share the board folder with a recipient.
	 *
	 * @param string $boardId Board slug (its folder).
	 * @param string $shareType 'user' | 'group' | 'team'.
	 * @param string $shareWith Recipient id (uid, gid, or team id).
	 * @param int $permissions Nextcloud permission bitmask (see SharePermissions).
	 *
	 * @return string The created share id.
	 */
	public function share(string $boardId, string $shareType, string $shareWith, int $permissions): string;

	/**
	 * List the shares currently on a board.
	 *
	 * @return list<array{id: string, type: string, with: string, permissions: int}>
	 */
	public function listShares(string $boardId): array;

	/**
	 * Remove a share by id.
	 */
	public function revoke(string $shareId): void;

	/**
	 * Boards shared TO the current user (folders holding a .board.yml).
	 *
	 * May contain duplicates when the same board reaches the user through more
	 * than one channel (direct + group); the service deduplicates by id.
	 *
	 * @return list<array{id: string, name: string, columns: list<string>, owner: string, permissions: int}>
	 */
	public function receivedBoards(): array;
}
