<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Kanban;

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FileBoardRepository.
 *
 * Layout: {rootDir}/{board-slug}/.board.yml + NN-prefixed column folders.
 *
 * @group sovereign-kanban
 */
final class FileBoardRepositoryTest extends TestCase {

	private string $rootDir;
	private FileBoardRepository $repo;

	protected function setUp(): void {
		$this->rootDir = sys_get_temp_dir() . '/kanban-boards-' . uniqid();
		mkdir($this->rootDir);
		$this->repo = new FileBoardRepository($this->rootDir);
	}

	public function testCreateWritesBoardYml(): void {
		$board = Board::create(name: 'Projets SdP', color: '#e85444');

		$this->repo->create($board);

		$yamlFile = $this->rootDir . '/projets-sdp/.board.yml';
		$this->assertFileExists($yamlFile);

		$parsed = yaml_parse(file_get_contents($yamlFile));
		$this->assertSame('Projets SdP', $parsed['name']);
		$this->assertSame('#e85444', $parsed['color']);
	}

	public function testCreateMakesNumberedColumnFolders(): void {
		$board = Board::create(name: 'Test', color: '#46ba61');

		$this->repo->create($board);

		$this->assertDirectoryExists($this->rootDir . '/test/01-Backlog');
		$this->assertDirectoryExists($this->rootDir . '/test/02-En cours');
		$this->assertDirectoryExists($this->rootDir . '/test/03-Terminé');
		$this->assertDirectoryExists($this->rootDir . '/test/04-Archivé');
	}

	public function testListReturnsEmptyWhenNoBoards(): void {
		$this->assertSame([], $this->repo->list());
	}

	public function testListReturnsAllBoards(): void {
		$this->repo->create(Board::create(name: 'Projets SdP', color: '#e85444'));
		$this->repo->create(Board::create(name: 'Cercle Différences', color: '#4488ee'));

		$boards = $this->repo->list();

		$this->assertCount(2, $boards);
		$names = array_map(fn (Board $b) => $b->name, $boards);
		$this->assertContains('Projets SdP', $names);
		$this->assertContains('Cercle Différences', $names);
	}

	public function testFindReturnsBoardById(): void {
		$this->repo->create(Board::create(name: 'Projets SdP', color: '#e85444'));

		$board = $this->repo->find('projets-sdp');

		$this->assertInstanceOf(Board::class, $board);
		$this->assertSame('Projets SdP', $board->name);
		$this->assertSame('#e85444', $board->color);
	}

	public function testFindReturnsNullWhenMissing(): void {
		$this->assertNull($this->repo->find('inexistant'));
	}

	public function testSaveRenamePreservesFolderAndUpdatesYml(): void {
		$this->repo->create(Board::create(name: 'Projets SdP', color: '#e85444'));

		$board = $this->repo->find('projets-sdp');
		$this->repo->save($board->withName('Projets Serveurs du Peuple'));

		// Le dossier (slug) ne bouge pas — pas de déplacement sur renommage.
		$this->assertDirectoryExists($this->rootDir . '/projets-sdp');
		// Toujours un seul tableau, avec le nouveau nom.
		$boards = $this->repo->list();
		$this->assertCount(1, $boards);
		$this->assertSame('Projets Serveurs du Peuple', $boards[0]->name);
	}

	public function testSaveColorChangePersists(): void {
		$this->repo->create(Board::create(name: 'Projets SdP', color: '#e85444'));

		$board = $this->repo->find('projets-sdp');
		$this->repo->save($board->withColor('#4488ee'));

		$this->assertSame('#4488ee', $this->repo->find('projets-sdp')->color);
	}

	public function testDeleteRemovesBoardFolder(): void {
		$this->repo->create(Board::create(name: 'Projets SdP', color: '#e85444'));

		$this->repo->delete('projets-sdp');

		$this->assertDirectoryDoesNotExist($this->rootDir . '/projets-sdp');
		$this->assertSame([], $this->repo->list());
	}

	protected function tearDown(): void {
		if (is_dir($this->rootDir)) {
			system('rm -rf ' . escapeshellarg($this->rootDir));
		}
	}
}
