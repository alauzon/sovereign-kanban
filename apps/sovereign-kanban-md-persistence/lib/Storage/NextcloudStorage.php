<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Storage;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Storage backed by the Nextcloud Files API, rooted at a Folder node.
 *
 * Going through the Files API (instead of raw filesystem) keeps NC's file
 * cache in sync: the Files app and desktop sync client see Kanban changes
 * immediately, and it works on object storage too.
 */
final class NextcloudStorage implements Storage {

	public function __construct(
		private readonly Folder $root,
	) {
	}

	public function exists(string $path): bool {
		return $path === '' ? true : $this->root->nodeExists($path);
	}

	public function isDir(string $path): bool {
		if ($path === '') {
			return true;
		}

		return $this->root->nodeExists($path) && $this->root->get($path) instanceof Folder;
	}

	public function makeDir(string $path): void {
		if ($path !== '') {
			$this->ensureFolder($path);
		}
	}

	public function read(string $path): string {
		$node = $this->root->get($path);

		return $node instanceof File ? $node->getContent() : '';
	}

	public function write(string $path, string $content): void {
		if ($this->root->nodeExists($path)) {
			$node = $this->root->get($path);
			if ($node instanceof File) {
				$node->putContent($content);
				return;
			}
		}

		$dir = dirname($path);
		$parent = ($dir === '' || $dir === '.') ? $this->root : $this->ensureFolder($dir);
		$parent->newFile(basename($path), $content);
	}

	public function delete(string $path): void {
		if ($path !== '' && $this->root->nodeExists($path)) {
			$this->root->get($path)->delete();
		}
	}

	public function move(string $from, string $to): void {
		$node = $this->root->get($from);
		$dir = dirname($to);
		if ($dir !== '' && $dir !== '.') {
			$this->ensureFolder($dir);
		}
		$node->move($this->root->getPath() . '/' . $to);
	}

	public function childDirectories(string $path): array {
		if ($path !== '' && !$this->root->nodeExists($path)) {
			return [];
		}
		$folder = $path === '' ? $this->root : $this->root->get($path);
		if (!$folder instanceof Folder) {
			return [];
		}

		$names = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$names[] = $node->getName();
			}
		}

		return $names;
	}

	public function childFiles(string $path): array {
		if ($path !== '' && !$this->root->nodeExists($path)) {
			return [];
		}
		$folder = $path === '' ? $this->root : $this->root->get($path);
		if (!$folder instanceof Folder) {
			return [];
		}

		$names = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$names[] = $node->getName();
			}
		}

		return $names;
	}

	public function scoped(string $path): Storage {
		return new self($this->ensureFolder($path));
	}

	/**
	 * Get (creating intermediate folders as needed) the Folder at $path.
	 */
	private function ensureFolder(string $path): Folder {
		if ($this->root->nodeExists($path)) {
			$node = $this->root->get($path);
			if ($node instanceof Folder) {
				return $node;
			}
		}

		$current = $this->root;
		$accumulated = '';
		foreach (explode('/', $path) as $part) {
			$accumulated = $accumulated === '' ? $part : $accumulated . '/' . $part;
			$current = $this->root->nodeExists($accumulated)
				? $this->root->get($accumulated)
				: $current->newFolder($part);
		}

		return $current;
	}
}
