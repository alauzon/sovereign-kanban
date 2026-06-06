<?php

/**
 * @file
 * FileCardRepository — persistence for cards via a Storage abstraction.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

use OCA\SovereignKanbanMdPersistence\Storage\Storage;

/**
 * Repository for cards, scoped to one board's Storage.
 *
 * Layout (relative to the board): {column}/{uuid}-{slug}/card.md
 * The Storage implementation decides whether that hits the raw filesystem
 * (tests) or the Nextcloud Files API (production, cache-synced).
 */
final class FileCardRepository {

	public function __construct(
		private readonly Storage $storage,
	) {
	}

	/**
	 * Save a card (creates {column}/{uuid}-{slug}/card.md).
	 */
	public function save(Card $card): void {
		$slug = str_replace(' ', '-', $card->title);
		$cardDir = $card->column . '/' . $card->id . '-' . $slug;
		$this->storage->write($cardDir . '/card.md', $this->serialize($card));
	}

	/**
	 * List all cards grouped by clean column name (NN- prefix stripped).
	 *
	 * @return array<string, Card[]>
	 */
	public function listByColumn(): array {
		$result = [];
		foreach ($this->storage->childDirectories('') as $columnFolder) {
			$cleanName = preg_replace('/^\d+-/', '', $columnFolder);
			$cards = [];
			foreach ($this->storage->childDirectories($columnFolder) as $cardFolder) {
				$file = $columnFolder . '/' . $cardFolder . '/card.md';
				if ($this->storage->exists($file)) {
					$cards[] = Card::fromMarkdown($this->storage->read($file));
				}
			}
			$result[$cleanName] = $cards;
		}

		return $result;
	}

	/**
	 * Resolve a clean column name to its NN-prefixed folder name, or null.
	 */
	public function resolveColumnFolder(string $cleanName): ?string {
		foreach ($this->storage->childDirectories('') as $columnFolder) {
			if (preg_replace('/^\d+-/', '', $columnFolder) === $cleanName) {
				return $columnFolder;
			}
		}

		return null;
	}

	/**
	 * Find a card by id across all columns, or null if absent.
	 */
	public function findById(string $cardId): ?Card {
		$dir = $this->findCardDirAnywhere($cardId);

		return ($dir !== null && $this->storage->exists($dir . '/card.md'))
			? Card::fromMarkdown($this->storage->read($dir . '/card.md'))
			: null;
	}

	/**
	 * Rewrite an existing card's card.md in place (keeps its directory).
	 */
	public function update(Card $card): void {
		$dir = $this->findCardDir($card->column, $card->id);
		if ($dir === null) {
			throw new \RuntimeException('Card not found: ' . $card->id);
		}

		$this->storage->write($dir . '/card.md', $this->serialize($card));
	}

	/**
	 * Move a card to another column, keeping its UUID and resyncing the
	 * card.md 'column' frontmatter to the new folder.
	 */
	public function moveCard(string $cardId, string $fromColumn, string $toColumn): void {
		$fromDir = $this->findCardDir($fromColumn, $cardId);
		if ($fromDir === null) {
			throw new \Exception("Card not found in column: $fromColumn");
		}

		$toDir = $toColumn . '/' . basename($fromDir);
		$this->storage->move($fromDir, $toDir);

		$file = $toDir . '/card.md';
		if ($this->storage->exists($file)) {
			$card = Card::fromMarkdown($this->storage->read($file));
			$this->storage->write($file, $this->serialize(new Card(
				id: $card->id,
				title: $card->title,
				column: $toColumn,
				description: $card->description,
				created_at: $card->created_at,
				assignees: $card->assignees,
				due_date: $card->due_date,
			)));
		}
	}

	/**
	 * Delete a card known to be in a given column.
	 */
	public function delete(string $cardId, string $column): void {
		$dir = $this->findCardDir($column, $cardId);
		if ($dir !== null) {
			$this->storage->delete($dir);
		}
	}

	/**
	 * Delete a card by id, wherever it lives.
	 */
	public function deleteById(string $cardId): void {
		$dir = $this->findCardDirAnywhere($cardId);
		if ($dir !== null) {
			$this->storage->delete($dir);
		}
	}

	/**
	 * Append a comment to a card's comments.md.
	 */
	public function addComment(string $cardId, Comment $comment): void {
		$dir = $this->findCardDirAnywhere($cardId);
		if ($dir === null) {
			throw new \RuntimeException('Card not found: ' . $cardId);
		}

		$file = $dir . '/comments.md';
		$existing = $this->storage->exists($file) ? rtrim($this->storage->read($file), "\n") : '';
		$separator = $existing === '' ? '' : "\n\n";
		$this->storage->write($file, $existing . $separator . $comment->toMarkdown());
	}

	/**
	 * List a card's comments in document order (empty if none).
	 *
	 * @return Comment[]
	 */
	public function listComments(string $cardId): array {
		$dir = $this->findCardDirAnywhere($cardId);
		if ($dir === null) {
			return [];
		}

		$file = $dir . '/comments.md';

		return $this->storage->exists($file) ? Comment::parseAll($this->storage->read($file)) : [];
	}

	/**
	 * Serialize a card to its card.md content (frontmatter + body).
	 */
	private function serialize(Card $card): string {
		return $card->toYAMLFrontmatter() . "\n\n" . $card->description;
	}

	/**
	 * Relative path to a card's directory within a column, or null.
	 */
	private function findCardDir(string $column, string $cardId): ?string {
		$prefix = substr($cardId, 0, 8);
		foreach ($this->storage->childDirectories($column) as $cardFolder) {
			if (str_starts_with($cardFolder, $prefix)) {
				return $column . '/' . $cardFolder;
			}
		}

		return null;
	}

	/**
	 * Relative path to a card's directory across all columns, or null.
	 */
	private function findCardDirAnywhere(string $cardId): ?string {
		foreach ($this->storage->childDirectories('') as $columnFolder) {
			$dir = $this->findCardDir($columnFolder, $cardId);
			if ($dir !== null) {
				return $dir;
			}
		}

		return null;
	}
}
