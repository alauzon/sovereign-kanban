<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Storage;

/**
 * Raw-filesystem Storage, rooted at a base directory.
 *
 * Used by unit tests (fast, no Nextcloud). In production the repositories
 * use NextcloudStorage instead so the file cache stays in sync.
 */
final class LocalStorage implements Storage {

	public function __construct(
		private readonly string $root,
	) {
	}

	public function exists(string $path): bool {
		return file_exists($this->abs($path));
	}

	public function isDir(string $path): bool {
		return is_dir($this->abs($path));
	}

	public function makeDir(string $path): void {
		$abs = $this->abs($path);
		if (!is_dir($abs)) {
			mkdir($abs, 0755, true);
		}
	}

	public function read(string $path): string {
		return (string) file_get_contents($this->abs($path));
	}

	public function mtime(string $path): ?int {
		$abs = $this->abs($path);
		return is_file($abs) ? (filemtime($abs) ?: null) : null;
	}

	public function write(string $path, string $content): void {
		$abs = $this->abs($path);
		$dir = dirname($abs);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		file_put_contents($abs, $content);
	}

	public function delete(string $path): void {
		$abs = $this->abs($path);
		if (is_dir($abs)) {
			$items = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST,
			);
			foreach ($items as $item) {
				$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
			}
			rmdir($abs);
		} elseif (is_file($abs)) {
			unlink($abs);
		}
	}

	public function move(string $from, string $to): void {
		$target = $this->abs($to);
		$dir = dirname($target);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		rename($this->abs($from), $target);
	}

	public function childDirectories(string $path): array {
		$abs = $this->abs($path);
		if (!is_dir($abs)) {
			return [];
		}

		$names = [];
		foreach (glob($abs . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
			$names[] = basename($dir);
		}

		return $names;
	}

	public function childFiles(string $path): array {
		$abs = $this->abs($path);
		if (!is_dir($abs)) {
			return [];
		}

		$names = [];
		foreach (glob($abs . '/*') ?: [] as $entry) {
			if (is_file($entry)) {
				$names[] = basename($entry);
			}
		}

		return $names;
	}

	public function scoped(string $path): Storage {
		return new self($this->abs($path));
	}

	private function abs(string $path): string {
		return $path === '' ? $this->root : $this->root . '/' . $path;
	}
}
