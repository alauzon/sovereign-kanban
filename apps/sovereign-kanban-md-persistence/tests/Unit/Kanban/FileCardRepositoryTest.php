<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Kanban;

use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FileCardRepository.
 *
 * @group sovereign-kanban
 */
final class FileCardRepositoryTest extends TestCase {

    private string $testDir;
    private FileCardRepository $repo;

    protected function setUp(): void {
        $this->testDir = sys_get_temp_dir() . '/kanban-test-' . uniqid();
        mkdir($this->testDir);
        mkdir($this->testDir . '/01-Backlog');
        mkdir($this->testDir . '/02-En cours');

        $this->repo = new FileCardRepository($this->testDir);
    }

    public function testSaveCardCreatesDirectoryWithCorrectName(): void {
        $card = Card::create(title: 'Configurer mail', column: '01-Backlog');

        $this->repo->save($card);

        // Directory must exist with pattern {uuid}-{slug}
        $dir = glob($this->testDir . '/01-Backlog/' . substr($card->id, 0, 8) . '*');
        $this->assertCount(1, $dir, "Card directory should exist");
        $this->assertDirectoryExists($dir[0]);
    }

    public function testSaveCardWritesCardMdWithFrontmatter(): void {
        $card = new Card(
            id: 'abc-123-def-456',
            title: 'Test Task',
            column: '01-Backlog',
            description: 'Test description',
            created_at: new \DateTime('2026-06-05T10:00:00Z'),
            assignees: ['alain'],
        );

        $this->repo->save($card);

        $cardFile = $this->testDir . '/01-Backlog/abc-123-def-456-Test-Task/card.md';
        $this->assertFileExists($cardFile);

        $content = file_get_contents($cardFile);
        $this->assertStringContainsString('---', $content);
        $this->assertStringContainsString('id: abc-123-def-456', $content);
        $this->assertStringContainsString('title: Test Task', $content);
    }

    public function testMoveCardPreservesUUID(): void {
        $card = Card::create(title: 'Task', column: '01-Backlog');
        $originalId = $card->id;

        $this->repo->save($card);
        $this->repo->moveCard($originalId, '01-Backlog', '02-En cours');

        // File must not be in old column
        $oldDir = glob($this->testDir . '/01-Backlog/' . substr($originalId, 0, 8) . '*');
        $this->assertCount(0, $oldDir, "Card should not be in old column");

        // But must be in new column
        $newDir = glob($this->testDir . '/02-En cours/' . substr($originalId, 0, 8) . '*');
        $this->assertCount(1, $newDir, "Card should be in new column");

        // UUID must be unchanged
        $cardFile = $newDir[0] . '/card.md';
        $content = file_get_contents($cardFile);
        $this->assertStringContainsString('id: ' . $originalId, $content);
    }

    public function testDeleteCardRemovesDirectory(): void {
        $card = Card::create(title: 'Delete me', column: '01-Backlog');

        $this->repo->save($card);
        $this->repo->delete($card->id, '01-Backlog');

        $dir = glob($this->testDir . '/01-Backlog/' . substr($card->id, 0, 8) . '*');
        $this->assertCount(0, $dir, "Card directory should be deleted");
    }

    protected function tearDown(): void {
        if (is_dir($this->testDir)) {
            system('rm -rf ' . escapeshellarg($this->testDir));
        }
    }
}
