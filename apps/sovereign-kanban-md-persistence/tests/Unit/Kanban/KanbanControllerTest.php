<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Kanban;

use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\KanbanController;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KanbanController REST API.
 *
 * @group sovereign-kanban
 */
final class KanbanControllerTest extends TestCase {

	private KanbanController $controller;
	private FileCardRepository $repository;
	private string $testDir;

	protected function setUp(): void {
		$this->testDir = sys_get_temp_dir() . '/kanban-test-' . uniqid();
		mkdir($this->testDir);
		mkdir($this->testDir . '/01-Backlog');
		mkdir($this->testDir . '/02-En cours');
		mkdir($this->testDir . '/03-Terminé');

		$this->repository = new FileCardRepository($this->testDir);
		$this->controller = new KanbanController($this->repository);
	}

	public function testGetBoardsReturnsColumnStructure(): void {
		$response = $this->controller->getBoards();

		$this->assertIsArray($response);
		$this->assertArrayHasKey('columns', $response);
		$this->assertIsArray($response['columns']);
		$this->assertArrayHasKey('01-Backlog', $response['columns']);
		$this->assertArrayHasKey('02-En cours', $response['columns']);
		$this->assertArrayHasKey('03-Terminé', $response['columns']);
	}

	public function testGetBoardsWithCardsReturnsColumnStructure(): void {
		// Create some test data
		$card = Card::create(title: 'Test task', column: '01-Backlog');
		$this->repository->save($card);

		$response = $this->controller->getBoards();

		// Response should be array with column info
		$this->assertIsArray($response);
		$this->assertArrayHasKey('columns', $response);
		$this->assertIsArray($response['columns']);
	}

	public function testCreateCardReturnsCardData(): void {
		$payload = [
			'title' => 'New task',
			'column' => '01-Backlog',
			'description' => 'Task description',
		];

		$response = $this->controller->createCard($payload);

		$this->assertIsArray($response);
		$this->assertArrayHasKey('id', $response);
		$this->assertArrayHasKey('title', $response);
		$this->assertEquals('New task', $response['title']);
		$this->assertEquals('01-Backlog', $response['column']);
	}

	public function testMoveCardChangesColumn(): void {
		// Create a card
		$card = Card::create(title: 'Task to move', column: '01-Backlog');
		$this->repository->save($card);

		// Move it
		$payload = [
			'cardId' => $card->id,
			'fromColumn' => '01-Backlog',
			'toColumn' => '02-En cours',
		];

		$response = $this->controller->moveCard($payload);

		$this->assertIsArray($response);
		$this->assertEquals('02-En cours', $response['column']);
	}

	public function testDeleteCardRemovesIt(): void {
		// Create a card
		$card = Card::create(title: 'Task to delete', column: '01-Backlog');
		$this->repository->save($card);

		// Delete it
		$payload = [
			'cardId' => $card->id,
			'column' => '01-Backlog',
		];

		$response = $this->controller->deleteCard($payload);

		$this->assertIsArray($response);
		$this->assertTrue($response['deleted']);
	}

	protected function tearDown(): void {
		if (is_dir($this->testDir)) {
			system('rm -rf ' . escapeshellarg($this->testDir));
		}
	}
}
