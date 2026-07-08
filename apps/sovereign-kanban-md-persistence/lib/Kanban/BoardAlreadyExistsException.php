<?php

/**
 * @file
 * Thrown when creating a board whose slug already exists on disk.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

use RuntimeException;

/**
 * A board with this slug already exists.
 *
 * Guards against the destructive collision where a new board's name slugifies
 * onto an existing board's folder: without this guard, create() would overwrite
 * the existing .board.yml (columns reset to defaults) and orphan every card that
 * lived in the old columns. The folder is the canonical data — never clobber it.
 */
final class BoardAlreadyExistsException extends RuntimeException {

	public function __construct(
		public readonly string $boardId,
	) {
		parent::__construct('Board already exists: ' . $boardId);
	}
}
