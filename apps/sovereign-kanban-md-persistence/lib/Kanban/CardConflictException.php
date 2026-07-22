<?php

/**
 * @file
 * Raised when a card write targets a revision that no longer matches disk.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

/**
 * A card field edit carried a base revision that no longer matches disk: someone
 * saved the card in between (Alain, 2026-07-21: « Allo » in one window, « Quoi »
 * in another, « Allo » silently lost). The controller maps this to HTTP 409 so
 * the loss becomes a visible « it changed, reload » — the card twin of
 * BoardConflictException. This DETECTS the clash; merging two edits of the same
 * text is real co-editing (carte 4ae523), a separate matter.
 */
final class CardConflictException extends \RuntimeException {

	public function __construct(
		public readonly string $cardId,
		public readonly int $expectedRev,
		public readonly int $actualRev,
	) {
		parent::__construct(sprintf(
			'Card "%s" changed under you (you had rev %d, disk is rev %d).',
			$cardId,
			$expectedRev,
			$actualRev,
		));
	}
}
