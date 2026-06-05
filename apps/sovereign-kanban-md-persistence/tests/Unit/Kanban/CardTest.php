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
}
