<?php

/**
 * @file
 * FileCardRepository — File-based persistence for cards.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Haiku 4.5)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

/**
 * Repository for storing cards as .md files in directory structure.
 *
 * Directory layout:
 *   {baseDir}/{column}/{uuid}-{slug}/card.md
 */
final class FileCardRepository {

	public function __construct(
		private string $baseDir,
	) {
	}

	/**
	 * Save a card to disk.
	 *
	 * Creates directory {baseDir}/{column}/{uuid}-{slug}/ and writes card.md.
	 */
	public function save(Card $card): void {
		$slug = str_replace(' ', '-', $card->title);
		$cardDir = $this->baseDir . '/' . $card->column . '/' . $card->id . '-' . $slug;

		if (!is_dir($cardDir)) {
			mkdir($cardDir, 0755, true);
		}

		$cardFile = $cardDir . '/card.md';
		$content = $card->toYAMLFrontmatter() . "\n\n" . $card->description;
		file_put_contents($cardFile, $content);
	}

	/**
	 * Move a card from one column to another.
	 *
	 * Preserves UUID and directory name.
	 */
	public function moveCard(string $cardId, string $fromColumn, string $toColumn): void {
		$fromDir = $this->findCardDir($this->baseDir . '/' . $fromColumn, $cardId);
		if (!$fromDir) {
			throw new \Exception("Card not found in column: $fromColumn");
		}

		if (!is_dir($this->baseDir . '/' . $toColumn)) {
			mkdir($this->baseDir . '/' . $toColumn, 0755, true);
		}

		$toDir = $this->baseDir . '/' . $toColumn . '/' . basename($fromDir);
		rename($fromDir, $toDir);
	}

	/**
	 * Delete a card.
	 */
	public function delete(string $cardId, string $column): void {
		$cardDir = $this->findCardDir($this->baseDir . '/' . $column, $cardId);
		if (!$cardDir) {
			return;
		}

		system('rm -rf ' . escapeshellarg($cardDir));
	}

	/**
	 * Find card directory by UUID prefix.
	 *
	 * @return string|null Full path to card directory, or null if not found
	 */
	private function findCardDir(string $columnDir, string $cardId): ?string {
		if (!is_dir($columnDir)) {
			return null;
		}

		$prefix = substr($cardId, 0, 8);
		$dirs = glob($columnDir . '/' . $prefix . '*', GLOB_ONLYDIR);

		return !empty($dirs) ? $dirs[0] : null;
	}

}
