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
