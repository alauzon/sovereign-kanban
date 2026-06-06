<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Kanban;

use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Card value object.
 *
 * @group sovereign-kanban
 */
final class CardTest extends TestCase {

    public function testCreateCardGeneratesUUID(): void {
        $card = Card::create(title: 'Configurer le mail', column: '01-Backlog');

        $this->assertNotEmpty($card->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $card->id);
    }

    public function testCardToYAMLFrontmatter(): void {
        $card = new Card(
            id: 'abc-123-def-456',
            title: 'Configurer le mail',
            column: '01-Backlog',
            description: 'Intégrer SMTP',
            created_at: new \DateTime('2026-06-05T10:30:00Z'),
            assignees: ['alain', 'steve'],
        );

        $yaml = $card->toYAMLFrontmatter();

        $this->assertStringStartsWith('---', $yaml);
        $this->assertStringContainsString('id: abc-123-def-456', $yaml);
        $this->assertStringContainsString('title: Configurer le mail', $yaml);
        $this->assertStringContainsString('column: 01-Backlog', $yaml);
        $this->assertStringContainsString('created_at: 2026-06-05T10:30:00Z', $yaml);
        $this->assertStringContainsString("assignees:\n  - alain\n  - steve", $yaml);
        $this->assertStringEndsWith('---', $yaml);
    }

    public function testCardImmutable(): void {
        $card = Card::create(title: 'Task', column: '01-Backlog');
        $originalId = $card->id;

        // Card is immutable — no setters
        $this->assertEquals($originalId, $card->id);
        $this->assertFalse(method_exists($card, 'setTitle'));
    }

    public function testCardFrontmatterHasAllRequiredFields(): void {
        $card = new Card(
            id: 'test-id',
            title: 'Test',
            column: '01-Backlog',
            description: 'Test desc',
            created_at: new \DateTime('2026-06-05T10:00:00Z'),
            assignees: [],
        );

        $yaml = $card->toYAMLFrontmatter();

        // Validate YAML is well-formed
        $parsed = yaml_parse($yaml);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('id', $parsed);
        $this->assertArrayHasKey('title', $parsed);
        $this->assertArrayHasKey('column', $parsed);
        $this->assertArrayHasKey('created_at', $parsed);
    }

    public function testFromMarkdownParsesFrontmatterAndBody(): void {
        $content = "---\n"
            . "id: abc-123\n"
            . "title: Configurer le mail\n"
            . "column: 01-Backlog\n"
            . "created_at: 2026-06-05T10:00:00Z\n"
            . "assignees:\n  - alain\n"
            . "---\n\n"
            . "Intégrer SMTP et tester l'envoi.";

        $card = Card::fromMarkdown($content);

        $this->assertSame('abc-123', $card->id);
        $this->assertSame('Configurer le mail', $card->title);
        $this->assertSame('01-Backlog', $card->column);
        $this->assertSame("Intégrer SMTP et tester l'envoi.", $card->description);
        $this->assertSame(['alain'], $card->assignees);
    }

    public function testFromMarkdownRoundTripsToYAMLFrontmatter(): void {
        $original = new Card(
            id: 'rt-1',
            title: 'Round trip',
            column: '02-En cours',
            description: 'Body text',
            created_at: new \DateTime('2026-06-05T10:00:00Z'),
            assignees: ['alain', 'steve'],
        );

        $reparsed = Card::fromMarkdown($original->toYAMLFrontmatter() . "\n\n" . $original->description);

        $this->assertSame($original->id, $reparsed->id);
        $this->assertSame($original->title, $reparsed->title);
        $this->assertSame($original->column, $reparsed->column);
        $this->assertSame($original->description, $reparsed->description);
        $this->assertSame($original->assignees, $reparsed->assignees);
    }

    public function testToArrayShapesCardForTheApi(): void {
        $card = new Card(
            id: 'abc',
            title: 'Task',
            column: '01-Backlog',
            description: 'desc',
            created_at: new \DateTime('2026-06-05T10:00:00Z'),
            assignees: ['alain', 'steve'],
            due_date: '2026-06-15',
        );

        $this->assertSame(
            [
                'id' => 'abc',
                'title' => 'Task',
                'column' => '01-Backlog',
                'due_date' => '2026-06-15',
                'assignees' => ['alain', 'steve'],
            ],
            $card->toArray(),
        );
    }

    public function testToArrayDueDateNullWhenUnset(): void {
        $card = Card::create(title: 'No deadline', column: '01-Backlog');

        $this->assertNull($card->toArray()['due_date']);
    }

    public function testFromMarkdownParsesDueDateAndAssignees(): void {
        $content = "---\n"
            . "id: d1\n"
            . "title: Échéance\n"
            . "column: 01-Backlog\n"
            . "due_date: 2026-06-15\n"
            . "assignees:\n  - alain\n  - steve\n"
            . "---\n\nCorps.";

        $card = Card::fromMarkdown($content);

        $this->assertSame('2026-06-15', $card->due_date);
        $this->assertSame(['alain', 'steve'], $card->assignees);
    }

    public function testToYAMLFrontmatterIncludesDueDateAndAssignees(): void {
        $card = new Card(
            id: 'd2',
            title: 'T',
            column: '01-Backlog',
            description: '',
            created_at: new \DateTime('2026-06-05T10:00:00Z'),
            assignees: ['alain'],
            due_date: '2026-06-20',
        );

        $yaml = $card->toYAMLFrontmatter();

        $this->assertStringContainsString('due_date: 2026-06-20', $yaml);
        $this->assertStringContainsString("assignees:\n  - alain", $yaml);
    }
}
