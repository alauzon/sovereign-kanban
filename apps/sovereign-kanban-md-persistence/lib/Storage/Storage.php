<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Storage;

/**
 * Minimal storage abstraction the Kanban repositories depend on.
 *
 * All paths are relative to the storage's root. Two implementations:
 * LocalStorage (raw filesystem — used by unit tests) and NextcloudStorage
 * (via the Nextcloud Files API — keeps NC's file cache in sync, so the
 * Files app and desktop client see changes immediately, both directions).
 */
interface Storage {

	public function exists(string $path): bool;

	public function isDir(string $path): bool;

	public function makeDir(string $path): void;

	public function read(string $path): string;

	/**
	 * Last-modified time of a file as a Unix timestamp, or null if it is absent.
	 */
	public function mtime(string $path): ?int;

	public function write(string $path, string $content): void;

	public function delete(string $path): void;

	public function move(string $from, string $to): void;

	/**
	 * Basenames of the immediate subdirectories of $path ('' = root).
	 *
	 * @return string[]
	 */
	public function childDirectories(string $path): array;

	/**
	 * Basenames of the immediate files in $path ('' = root).
	 *
	 * @return string[]
	 */
	public function childFiles(string $path): array;

	/**
	 * Return a storage rooted at $path within this one.
	 */
	public function scoped(string $path): Storage;
}
