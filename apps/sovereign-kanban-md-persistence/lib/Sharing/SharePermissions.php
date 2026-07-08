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
}
