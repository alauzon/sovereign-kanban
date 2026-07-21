<?php

/**
 * @file
 * Raised when a board write targets a revision that no longer matches disk.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

/**
 * A structural board write (columns, palette, name, colour) carried a base
 * revision that no longer matches the one on disk: someone else wrote in between.
 *
 * Nisha's analysis of e0442c (2026-07-20 incident): the app rebuilt the whole
 * board object client-side and imposed it, so the last writer silently erased the
 * other's change. Refusing the stale write turns a silent loss into a visible
 * « it moved, reload » — the caller (controller) maps this to HTTP 409.
 */
final class BoardConflictException extends \RuntimeException {

	public function __construct(
		public readonly string $boardId,
		public readonly int $expectedRev,
		public readonly int $actualRev,
	) {
		parent::__construct(sprintf(
			'Board "%s" changed under you (you had rev %d, disk is rev %d).',
			$boardId,
			$expectedRev,
			$actualRev,
		));
	}
}
