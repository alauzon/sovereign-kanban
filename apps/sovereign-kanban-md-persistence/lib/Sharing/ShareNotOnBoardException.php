<?php

/**
 * @file
 * Thrown when revoking a share that does not belong to the given board.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Sharing;

use RuntimeException;

/**
 * The share id is not one of the board's shares.
 *
 * Stops a board owner from revoking another board's share by knowing (or
 * guessing) its id. The controller maps this to HTTP 404.
 */
final class ShareNotOnBoardException extends RuntimeException {

	public function __construct(
		public readonly string $boardId,
		public readonly string $shareId,
	) {
		parent::__construct('Share ' . $shareId . ' is not on board ' . $boardId);
	}
}
