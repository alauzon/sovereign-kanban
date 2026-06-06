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
	 * List all cards grouped by column.
	 *
	 * Scans each column folder under baseDir, reads every card.md, and
	 * groups the cards by clean column name (NN- prefix stripped). Empty
	 * columns map to an empty array.
	 *
	 * @return array<string, Card[]>
	 */
	public function listByColumn(): array {
		$result = [];
		foreach (glob($this->baseDir . '/*', GLOB_ONLYDIR) ?: [] as $columnDir) {
			$cleanName = preg_replace('/^\d+-/', '', basename($columnDir));
			$cards = [];
			foreach (glob($columnDir . '/*', GLOB_ONLYDIR) ?: [] as $cardDir) {
				$cardFile = $cardDir . '/card.md';
				if (is_file($cardFile)) {
					$cards[] = Card::fromMarkdown(file_get_contents($cardFile));
				}
			}
			$result[$cleanName] = $cards;
		}

		return $result;
	}

	/**
	 * Resolve a clean column name to its NN-prefixed folder name.
	 *
	 * The UI works with clean names ('Backlog'); on disk the folders are
	 * ordered with a numeric prefix ('01-Backlog'). Returns null if no
	 * matching column folder exists.
	 */
	public function resolveColumnFolder(string $cleanName): ?string {
		foreach (glob($this->baseDir . '/*', GLOB_ONLYDIR) ?: [] as $columnDir) {
			if (preg_replace('/^\d+-/', '', basename($columnDir)) === $cleanName) {
				return basename($columnDir);
			}
		}

		return null;
	}

	/**
	 * Find a card by id across all columns, or null if absent.
	 */
	public function findById(string $cardId): ?Card {
		$cardDir = $this->findCardDirAnywhere($cardId);

		return ($cardDir !== null && is_file($cardDir . '/card.md'))
			? Card::fromMarkdown(file_get_contents($cardDir . '/card.md'))
			: null;
	}

	/**
	 * Append a comment to a card's comments.md.
	 */
	public function addComment(string $cardId, Comment $comment): void {
		$cardDir = $this->findCardDirAnywhere($cardId);
		if ($cardDir === null) {
			throw new \RuntimeException('Card not found: ' . $cardId);
		}

		$file = $cardDir . '/comments.md';
		$existing = is_file($file) ? rtrim(file_get_contents($file), "\n") : '';
		$separator = $existing === '' ? '' : "\n\n";
		file_put_contents($file, $existing . $separator . $comment->toMarkdown());
	}

	/**
	 * List a card's comments in document order (empty if none).
	 *
	 * @return Comment[]
	 */
	public function listComments(string $cardId): array {
		$cardDir = $this->findCardDirAnywhere($cardId);
		if ($cardDir === null) {
			return [];
		}

		$file = $cardDir . '/comments.md';

		return is_file($file) ? Comment::parseAll(file_get_contents($file)) : [];
	}

	/**
	 * Delete a card by id, wherever it lives.
	 */
	public function deleteById(string $cardId): void {
		$cardDir = $this->findCardDirAnywhere($cardId);
		if ($cardDir !== null) {
			$this->removeRecursive($cardDir);
		}
	}

	/**
	 * Recursively remove a directory using native PHP calls (keeps PHP's
	 * stat cache consistent, unlike shelling out to rm).
	 */
	private function removeRecursive(string $path): void {
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($path);
	}

	/**
	 * Locate a card's directory across all columns, or null if absent.
	 */
	private function findCardDirAnywhere(string $cardId): ?string {
		foreach (glob($this->baseDir . '/*', GLOB_ONLYDIR) ?: [] as $columnDir) {
			$cardDir = $this->findCardDir($columnDir, $cardId);
			if ($cardDir !== null) {
				return $cardDir;
			}
		}

		return null;
	}

	/**
	 * Rewrite an existing card's card.md in place.
	 *
	 * Keeps the card directory (id prefix is stable); only the file content
	 * changes. Throws if the card is not found in its column.
	 */
	public function update(Card $card): void {
		$cardDir = $this->findCardDir($this->baseDir . '/' . $card->column, $card->id);
		if ($cardDir === null) {
			throw new \RuntimeException('Card not found: ' . $card->id);
		}

		$content = $card->toYAMLFrontmatter() . "\n\n" . $card->description;
		file_put_contents($cardDir . '/card.md', $content);
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

		// Keep the card's frontmatter column in sync with its new folder.
		$cardFile = $toDir . '/card.md';
		if (is_file($cardFile)) {
			$card = Card::fromMarkdown(file_get_contents($cardFile));
			$moved = new Card(
				id: $card->id,
				title: $card->title,
				column: $toColumn,
				description: $card->description,
				created_at: $card->created_at,
				assignees: $card->assignees,
				due_date: $card->due_date,
			);
			file_put_contents($cardFile, $moved->toYAMLFrontmatter() . "\n\n" . $moved->description);
		}
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
