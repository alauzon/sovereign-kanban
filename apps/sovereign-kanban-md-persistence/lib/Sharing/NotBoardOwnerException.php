<?php

/**
 * @file
 * Thrown when a non-owner tries to share a board.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Sharing;

use RuntimeException;

/**
 * The current user does not own the board, so may not share it.
 *
 * Sharing is owner-only: only the person whose Files hold the board folder can
 * grant access to it. The controller maps this to HTTP 403.
 */
final class NotBoardOwnerException extends RuntimeException {

	public function __construct(
		public readonly string $boardId,
	) {
		parent::__construct('Not the owner of board: ' . $boardId);
	}
}
