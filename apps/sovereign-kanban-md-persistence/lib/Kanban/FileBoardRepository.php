<?php

/**
 * @file
 * FileBoardRepository — File-based persistence for boards.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

use DateTime;
use Symfony\Component\Yaml\Yaml;

/**
 * Repository for boards stored as directories under the Kanban root.
 *
 * Directory layout:
 *   {rootDir}/{board-slug}/.board.yml
 *   {rootDir}/{board-slug}/01-Backlog/  02-En cours/  ...
 *
 * The board id (slug) is the folder name and stays stable, so a rename
 * only rewrites .board.yml — the folder never moves.
 */
final class FileBoardRepository {

	public function __construct(
		private string $rootDir,
	) {
	}

	/**
	 * Create a board: its folder, .board.yml, and numbered column folders.
	 */
	public function create(Board $board): void {
		$dir = $this->boardDir($board->id);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($dir . '/.board.yml', $board->toYaml());

		foreach (array_values($board->columns) as $index => $name) {
			$columnDir = sprintf('%s/%02d-%s', $dir, $index + 1, $name);
			if (!is_dir($columnDir)) {
				mkdir($columnDir, 0755, true);
			}
		}
	}

	/**
	 * Persist board config changes (rename, recolor).
	 *
	 * Only rewrites .board.yml; the folder is keyed by the stable slug.
	 */
	public function save(Board $board): void {
		$dir = $this->boardDir($board->id);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($dir . '/.board.yml', $board->toYaml());
	}

	/**
	 * List all boards found under the root.
	 *
	 * @return Board[]
	 */
	public function list(): array {
		$boards = [];
		foreach (glob($this->rootDir . '/*/.board.yml') as $ymlFile) {
			$boards[] = $this->loadFromYml($ymlFile);
		}

		return $boards;
	}

	/**
	 * Load a single board by id (slug), or null if it does not exist.
	 */
	public function find(string $id): ?Board {
		$ymlFile = $this->boardDir($id) . '/.board.yml';

		return is_file($ymlFile) ? $this->loadFromYml($ymlFile) : null;
	}

	/**
	 * Delete a board and its entire folder.
	 */
	public function delete(string $id): void {
		$dir = $this->boardDir($id);
		if (is_dir($dir)) {
			$this->removeRecursive($dir);
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

		$dir = $this->boardDir($boardId);
		$index = count(glob($dir . '/*', GLOB_ONLYDIR) ?: []) + 1;
		$columnDir = sprintf('%s/%02d-%s', $dir, $index, $name);
		if (!is_dir($columnDir)) {
			mkdir($columnDir, 0755, true);
		}

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

		$dir = $this->boardDir($boardId);
		$fromDir = $this->columnFolderPath($dir, $from);
		if ($fromDir !== null) {
			$prefix = preg_match('/^(\d+-)/', basename($fromDir), $m) ? $m[1] : '';
			$toDir = $dir . '/' . $prefix . $to;
			if ($fromDir !== $toDir) {
				rename($fromDir, $toDir);
				$this->resyncCardColumns($toDir);
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

		$columnDir = $this->columnFolderPath($this->boardDir($boardId), $name);
		if ($columnDir !== null) {
			$this->removeRecursive($columnDir);
		}

		return $updated;
	}

	/**
	 * Reorder columns. Display order comes from .board.yml, so this only
	 * rewrites the config — no folder changes needed.
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
	private function columnFolderPath(string $boardDir, string $cleanName): ?string {
		foreach (glob($boardDir . '/*', GLOB_ONLYDIR) ?: [] as $columnDir) {
			if (preg_replace('/^\d+-/', '', basename($columnDir)) === $cleanName) {
				return $columnDir;
			}
		}

		return null;
	}

	/**
	 * Rewrite the 'column' frontmatter of every card in a column folder to
	 * match the folder's (possibly new) name.
	 */
	private function resyncCardColumns(string $columnDir): void {
		$folderName = basename($columnDir);
		foreach (glob($columnDir . '/*', GLOB_ONLYDIR) ?: [] as $cardDir) {
			$file = $cardDir . '/card.md';
			if (!is_file($file)) {
				continue;
			}
			$card = Card::fromMarkdown(file_get_contents($file));
			if ($card->column === $folderName) {
				continue;
			}
			$synced = new Card(
				id: $card->id,
				title: $card->title,
				column: $folderName,
				description: $card->description,
				created_at: $card->created_at,
				assignees: $card->assignees,
				due_date: $card->due_date,
			);
			file_put_contents($file, $synced->toYAMLFrontmatter() . "\n\n" . $synced->description);
		}
	}

	/**
	 * Recursively remove a directory using native PHP calls.
	 *
	 * Native unlink()/rmdir() keep PHP's stat cache consistent, unlike
	 * shelling out to rm.
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
	 * Absolute path to a board's folder.
	 */
	private function boardDir(string $id): string {
		return $this->rootDir . '/' . $id;
	}

	/**
	 * Rebuild a Board from a .board.yml file.
	 */
	private function loadFromYml(string $ymlFile): Board {
		$data = Yaml::parse(file_get_contents($ymlFile));

		return new Board(
			id: $data['id'],
			name: $data['name'],
			color: $data['color'],
			columns: $data['columns'] ?? [],
			created_at: new DateTime($data['created_at']),
		);
	}
}
