<?php

/**
 * @file
 * Maps a board share level to a Nextcloud permission bitmask.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Sharing;

use InvalidArgumentException;

/**
 * Pure mapping of a share level (read / collaborate) to a permission bitmask.
 *
 * The bit values mirror OCP\Constants::PERMISSION_* (stable Nextcloud values),
 * kept local so this class has no OCP dependency and runs in the unit suite.
 * The SHARE bit is deliberately never granted: invitees cannot re-share
 * (sharing spec §10.5).
 */
final class SharePermissions {

	public const READ = 1;
	public const UPDATE = 2;
	public const CREATE = 4;
	public const DELETE = 8;
	public const SHARE = 16;

	private const COLLABORATE = self::READ | self::UPDATE | self::CREATE | self::DELETE;

	/**
	 * Resolve a level name to its permission bitmask.
	 *
	 * @param string $level 'read' (read-only) or 'collaborate' (move/edit cards).
	 *
	 * @throws InvalidArgumentException On an unknown level.
	 */
	public static function forLevel(string $level): int {
		return match ($level) {
			'read' => self::READ,
			'collaborate' => self::COLLABORATE,
			default => throw new InvalidArgumentException('Unknown share level: ' . $level),
		};
	}

	/**
	 * Whether a permission bitmask grants write access (create/move/edit cards).
	 *
	 * The board write endpoints gate on this: a recipient whose share carries
	 * only READ must be refused, even though the folder node — resolved in the
	 * owner's scope — reports the owner's full permissions. Authorization must
	 * come from the share's granted permission, never the node (fix 2026-07-12).
	 *
	 * @param int $permissions A Nextcloud permission bitmask (a share's granted
	 *   permissions).
	 */
	public static function allowsWrite(int $permissions): bool {
		return ($permissions & self::UPDATE) !== 0;
	}
}
