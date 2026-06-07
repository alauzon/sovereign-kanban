<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Kanban;

use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\Comment;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCA\SovereignKanbanMdPersistence\Storage\LocalStorage;
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

        $this->repo = new FileCardRepository(new LocalStorage($this->testDir));
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

    public function testListByColumnGroupsCardsUnderCleanColumnNames(): void {
        $this->repo->save(Card::create(title: 'First', column: '01-Backlog'));
        $this->repo->save(Card::create(title: 'Second', column: '01-Backlog'));
        $this->repo->save(Card::create(title: 'Third', column: '02-En cours'));

        $byColumn = $this->repo->listByColumn();

        // Keys are clean column names (NN- prefix stripped).
        $this->assertArrayHasKey('Backlog', $byColumn);
        $this->assertArrayHasKey('En cours', $byColumn);
        $this->assertCount(2, $byColumn['Backlog']);
        $this->assertCount(1, $byColumn['En cours']);
        $this->assertContainsOnlyInstancesOf(Card::class, $byColumn['Backlog']);

        $titles = array_map(static fn (Card $c) => $c->title, $byColumn['Backlog']);
        sort($titles);
        $this->assertSame(['First', 'Second'], $titles);
    }

    public function testListByColumnReturnsEmptyArraysForEmptyColumns(): void {
        $byColumn = $this->repo->listByColumn();

        $this->assertSame([], $byColumn['Backlog']);
        $this->assertSame([], $byColumn['En cours']);
    }

    public function testResolveColumnFolderMapsCleanNameToPrefixedFolder(): void {
        // setUp created '01-Backlog' and '02-En cours'.
        $this->assertSame('01-Backlog', $this->repo->resolveColumnFolder('Backlog'));
        $this->assertSame('02-En cours', $this->repo->resolveColumnFolder('En cours'));
        $this->assertNull($this->repo->resolveColumnFolder('Inexistante'));
    }

    public function testFindByIdReturnsCardFromAnyColumn(): void {
        $card = Card::create(title: 'Find me', column: '02-En cours');
        $this->repo->save($card);

        $found = $this->repo->findById($card->id);

        $this->assertInstanceOf(Card::class, $found);
        $this->assertSame('Find me', $found->title);
        $this->assertSame('02-En cours', $found->column);
        $this->assertNull($this->repo->findById('00000000-no-such-card'));
    }

    public function testUpdateRewritesCardInPlacePreservingId(): void {
        $card = Card::create(title: 'Original', column: '01-Backlog');
        $this->repo->save($card);

        $updated = new Card(
            id: $card->id,
            title: 'Edited title',
            column: '01-Backlog',
            description: 'New body text',
            created_at: $card->created_at,
            assignees: [],
        );
        $this->repo->update($updated);

        $found = $this->repo->findById($card->id);
        $this->assertSame('Edited title', $found->title);
        $this->assertSame('New body text', $found->description);
        $this->assertSame($card->id, $found->id);
    }

    public function testAddAndListComments(): void {
        $card = Card::create(title: 'With comments', column: '01-Backlog');
        $this->repo->save($card);

        $this->assertSame([], $this->repo->listComments($card->id));

        $this->repo->addComment($card->id, Comment::create('admin', 'Premier commentaire'));
        $this->repo->addComment($card->id, Comment::create('alain', "Deuxième\nsur deux lignes"));

        $list = $this->repo->listComments($card->id);
        $this->assertCount(2, $list);
        $this->assertSame('Premier commentaire', $list[0]->body);
        $this->assertSame('alain', $list[1]->author);
        $this->assertSame("Deuxième\nsur deux lignes", $list[1]->body);
    }

    public function testUpdateCommentChangesBodyButKeepsIdAuthorAndDate(): void {
        $card = Card::create(title: 'Editable comments', column: '01-Backlog');
        $this->repo->save($card);
        $this->repo->addComment($card->id, Comment::create('admin', 'Avant'));
        $original = $this->repo->listComments($card->id)[0];

        $ok = $this->repo->updateComment($card->id, $original->id, 'Après **édité**');

        $this->assertTrue($ok);
        $list = $this->repo->listComments($card->id);
        $this->assertCount(1, $list);
        $this->assertSame($original->id, $list[0]->id);
        $this->assertSame('admin', $list[0]->author);
        $this->assertSame('Après **édité**', $list[0]->body);
    }

    public function testUpdateCommentReturnsFalseForUnknownId(): void {
        $card = Card::create(title: 'No such comment', column: '01-Backlog');
        $this->repo->save($card);
        $this->repo->addComment($card->id, Comment::create('admin', 'Seul'));

        $this->assertFalse($this->repo->updateComment($card->id, 'nope', 'x'));
    }

    public function testDeleteCommentRemovesOnlyTheTargetedOne(): void {
        $card = Card::create(title: 'Deletable comments', column: '01-Backlog');
        $this->repo->save($card);
        $this->repo->addComment($card->id, Comment::create('admin', 'Garder'));
        $this->repo->addComment($card->id, Comment::create('alain', 'Supprimer'));
        $toDelete = $this->repo->listComments($card->id)[1];

        $ok = $this->repo->deleteComment($card->id, $toDelete->id);

        $this->assertTrue($ok);
        $list = $this->repo->listComments($card->id);
        $this->assertCount(1, $list);
        $this->assertSame('Garder', $list[0]->body);
    }

    public function testDeleteCommentReturnsFalseForUnknownId(): void {
        $card = Card::create(title: 'Delete unknown', column: '01-Backlog');
        $this->repo->save($card);
        $this->repo->addComment($card->id, Comment::create('admin', 'Seul'));

        $this->assertFalse($this->repo->deleteComment($card->id, 'nope'));
    }

    public function testMoveCardUpdatesFrontmatterColumn(): void {
        $card = Card::create(title: 'Mover', column: '01-Backlog');
        $this->repo->save($card);

        $this->repo->moveCard($card->id, '01-Backlog', '02-En cours');

        $moved = $this->repo->findById($card->id);
        $this->assertSame('02-En cours', $moved->column, 'Frontmatter column should follow the move');
    }

    public function testDeleteByIdRemovesCardFromAnyColumn(): void {
        $card = Card::create(title: 'Delete me', column: '02-En cours');
        $this->repo->save($card);
        $this->assertNotNull($this->repo->findById($card->id));

        $this->repo->deleteById($card->id);

        $this->assertNull($this->repo->findById($card->id));
    }

    protected function tearDown(): void {
        if (is_dir($this->testDir)) {
            system('rm -rf ' . escapeshellarg($this->testDir));
        }
    }
}
