<?php

/**
 * @file
 * FileBoardRepository — persistence for boards via a Storage abstraction.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

use OCA\SovereignKanbanMdPersistence\Storage\Storage;
use DateTime;
use Symfony\Component\Yaml\Yaml;

/**
 * Repository for boards, rooted at the Kanban root Storage.
 *
 * Layout (relative to the Kanban root):
 *   {board-slug}/.board.yml
 *   {board-slug}/01-Backlog/  02-En cours/  ...
 *
 * The board id (slug) is the folder name and stays stable; a rename only
 * rewrites .board.yml. The Storage decides raw-filesystem (tests) vs the
 * Nextcloud Files API (production).
 */
final class FileBoardRepository {

	public function __construct(
		private readonly Storage $storage,
	) {
	}

	/**
	 * Create a board: its .board.yml and numbered column folders.
	 */
	public function create(Board $board): void {
		$this->storage->write($board->id . '/.board.yml', $board->toYaml());
		foreach (array_values($board->columns) as $index => $name) {
			$this->storage->makeDir(sprintf('%s/%02d-%s', $board->id, $index + 1, $name));
		}
	}

	/**
	 * Persist board config changes (rename, recolor) — .board.yml only.
	 */
	public function save(Board $board): void {
		$this->storage->write($board->id . '/.board.yml', $board->toYaml());
	}

	/**
	 * List all boards.
	 *
	 * @return Board[]
	 */
	public function list(): array {
		$boards = [];
		foreach ($this->storage->childDirectories('') as $slug) {
			$yml = $slug . '/.board.yml';
			if ($this->storage->exists($yml)) {
				$boards[] = $this->loadFromYml($this->storage->read($yml));
			}
		}

		return $boards;
	}

	/**
	 * Load a single board by id (slug), or null.
	 */
	public function find(string $id): ?Board {
		$yml = $id . '/.board.yml';

		return $this->storage->exists($yml) ? $this->loadFromYml($this->storage->read($yml)) : null;
	}

	/**
	 * Delete a board and its entire folder.
	 */
	public function delete(string $id): void {
		if ($this->storage->exists($id)) {
			$this->storage->delete($id);
		}
	}

	/**
	 * Add a column: append to .board.yml and create its folder.
	 */
	public function addColumn(string $boardId, string $name): ?Board {
		$board = $this->find($boardId);
		if ($board === null) {
			return null;
		}

		$updated = $board->addColumn($name);
		$this->save($updated);

		$index = count($this->storage->childDirectories($boardId)) + 1;
		$this->storage->makeDir(sprintf('%s/%02d-%s', $boardId, $index, $name));

		return $updated;
	}

	/**
	 * Rename a column in .board.yml, rename its folder, and resync the
	 * card.md frontmatter of the cards it holds.
	 */
	public function renameColumn(string $boardId, string $from, string $to): ?Board {
		$board = $this->find($boardId);
		if ($board === null) {
			return null;
		}

		$updated = $board->renameColumn($from, $to);
		$this->save($updated);

		$fromFolder = $this->columnFolder($boardId, $from);
		if ($fromFolder !== null) {
			$prefix = preg_match('/^(\d+-)/', $fromFolder, $m) ? $m[1] : '';
			$toFolder = $prefix . $to;
			if ($fromFolder !== $toFolder) {
				$this->storage->move($boardId . '/' . $fromFolder, $boardId . '/' . $toFolder);
				$this->resyncCardColumns($boardId, $toFolder);
			}
		}

		return $updated;
	}

	/**
	 * Remove a column from .board.yml and delete its folder (and cards).
	 */
	public function removeColumn(string $boardId, string $name): ?Board {
		$board = $this->find($boardId);
		if ($board === null) {
			return null;
		}

		$updated = $board->removeColumn($name);
		$this->save($updated);

		$folder = $this->columnFolder($boardId, $name);
		if ($folder !== null) {
			$this->storage->delete($boardId . '/' . $folder);
		}

		return $updated;
	}

	/**
	 * Reorder columns (display order lives in .board.yml — config only).
	 */
	public function reorderColumns(string $boardId, array $orderedNames): ?Board {
		$board = $this->find($boardId);
		if ($board === null) {
			return null;
		}

		$updated = $board->withColumns($orderedNames);
		$this->save($updated);

		return $updated;
	}

	/**
	 * Find a column folder inside a board by its clean (NN-stripped) name.
	 */
	private function columnFolder(string $boardId, string $cleanName): ?string {
		foreach ($this->storage->childDirectories($boardId) as $folder) {
			if (preg_replace('/^\d+-/', '', $folder) === $cleanName) {
				return $folder;
			}
		}

		return null;
	}

	/**
	 * Rewrite the 'column' frontmatter of every card in a column folder.
	 */
	private function resyncCardColumns(string $boardId, string $columnFolder): void {
		$base = $boardId . '/' . $columnFolder;
		foreach ($this->storage->childDirectories($base) as $cardFolder) {
			$file = $base . '/' . $cardFolder . '/card.md';
			if (!$this->storage->exists($file)) {
				continue;
			}
			$card = Card::fromMarkdown($this->storage->read($file));
			if ($card->column === $columnFolder) {
				continue;
			}
			$synced = $card->withColumn($columnFolder);
			$this->storage->write($file, $synced->toYAMLFrontmatter() . "\n\n" . $synced->description);
		}
	}

	/**
	 * Rebuild a Board from .board.yml content.
	 */
	private function loadFromYml(string $content): Board {
		$data = Yaml::parse($content);

		return new Board(
			id: $data['id'],
			name: $data['name'],
			color: $data['color'],
			columns: $data['columns'] ?? [],
			created_at: new DateTime($data['created_at']),
			tags: $data['tags'] ?? [],
		);
	}
}
