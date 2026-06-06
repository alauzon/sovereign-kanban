<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Storage;

use OCA\SovereignKanbanMdPersistence\Storage\LocalStorage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LocalStorage.
 *
 * @group sovereign-kanban
 */
final class LocalStorageTest extends TestCase {

	private string $root;
	private LocalStorage $storage;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/sk-storage-' . uniqid();
		mkdir($this->root);
		$this->storage = new LocalStorage($this->root);
	}

	public function testWriteCreatesParentDirsAndReadReturnsContent(): void {
		$this->storage->write('a/b/c.txt', 'hello');

		$this->assertTrue($this->storage->exists('a/b/c.txt'));
		$this->assertSame('hello', $this->storage->read('a/b/c.txt'));
	}

	public function testChildDirectoriesListsImmediateSubdirs(): void {
		$this->storage->makeDir('01-Backlog');
		$this->storage->makeDir('02-En cours');
		$this->storage->write('not-a-dir.txt', 'x');

		$dirs = $this->storage->childDirectories('');
		sort($dirs);

		$this->assertSame(['01-Backlog', '02-En cours'], $dirs);
	}

	public function testMoveRelocatesADirectory(): void {
		$this->storage->write('01-Backlog/card/card.md', 'data');

		$this->storage->move('01-Backlog/card', '02-Done/card');

		$this->assertFalse($this->storage->exists('01-Backlog/card'));
		$this->assertSame('data', $this->storage->read('02-Done/card/card.md'));
	}

	public function testDeleteRemovesRecursively(): void {
		$this->storage->write('col/card/card.md', 'data');

		$this->storage->delete('col/card');

		$this->assertFalse($this->storage->exists('col/card'));
	}

	public function testScopedRootsWithinSubPath(): void {
		$this->storage->write('board/.board.yml', 'cfg');
		$scoped = $this->storage->scoped('board');

		$this->assertSame('cfg', $scoped->read('.board.yml'));
		$this->assertTrue($scoped->exists('.board.yml'));
	}

	protected function tearDown(): void {
		if (is_dir($this->root)) {
			system('rm -rf ' . escapeshellarg($this->root));
		}
	}
}
