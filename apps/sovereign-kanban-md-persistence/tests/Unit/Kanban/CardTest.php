<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Kanban;

use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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
        $this->assertStringEndsWith('---', $yaml);

        // Assert the meaning, not the bytes: the emitter quotes whatever YAML
        // would otherwise misread (any value with a space, a colon, a leading
        // '#'…), so pinning the exact string here would pin an escaping detail
        // and break the moment the emitter gets safer. What must hold is that
        // the frontmatter parses back to these values.
        $parsed = Yaml::parse(trim($yaml, "-\n"));

        $this->assertSame('abc-123-def-456', $parsed['id']);
        $this->assertSame('Configurer le mail', $parsed['title']);
        $this->assertSame('01-Backlog', $parsed['column']);
        $this->assertSame('2026-06-05T10:30:00Z', $parsed['created_at']);
        $this->assertSame(['alain', 'steve'], $parsed['assignees']);
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

        // Validate YAML is well-formed. Parsed with symfony/yaml — the very
        // parser the app reads cards with — instead of ext-yaml, which is
        // absent from most local PHP and made this test ERROR for months.
        $parsed = Yaml::parse(trim($yaml, "-\n"));
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
            start_date: '2026-06-10',
        );

        $this->assertSame(
            [
                'id' => 'abc',
                'title' => 'Task',
                'column' => '01-Backlog',
                'due_date' => '2026-06-15',
                'start_date' => '2026-06-10',
                'created_at' => '2026-06-05T10:00:00Z',
                'assignees' => ['alain', 'steve'],
                'procedures' => [],
                'priority' => null,
                'tags' => [],
                'phase' => null,
                'excerpt' => 'desc',
            ],
            $card->toArray(),
        );
    }

    public function testToArrayIncludesPlainTextExcerptFromDescription(): void {
        $card = new Card(
            id: 'abc',
            title: 'Task',
            column: '01-Backlog',
            description: "# Titre\n\nCeci est **gras** et un [lien](https://ex.com).\n\n| A | B |\n|---|---|\n| 1 | 2 |",
        );

        $this->assertSame(
            'Titre Ceci est gras et un lien. A B 1 2',
            $card->toArray()['excerpt'],
        );
    }

    public function testToArrayExcerptIsTruncatedWithEllipsis(): void {
        $long = str_repeat('mot ', 60);
        $card = new Card(id: 'abc', title: 'T', column: '01-Backlog', description: $long);

        $excerpt = $card->toArray()['excerpt'];
        $this->assertLessThanOrEqual(140, mb_strlen($excerpt));
        $this->assertStringEndsWith('…', $excerpt);
    }

    public function testToArrayExcerptEmptyWhenNoDescription(): void {
        $card = Card::create(title: 'No body', column: '01-Backlog');

        $this->assertSame('', $card->toArray()['excerpt']);
    }

    public function testProceduresRoundTripThroughFrontmatter(): void {
        $card = new Card(
            id: 'p1',
            title: 'Réunion',
            column: '02-En cours',
            description: 'corps',
            procedures: ['Élection sans candidat', 'Décision par consentement'],
        );

        $restored = Card::fromMarkdown($card->toYAMLFrontmatter() . "\n" . $card->description);

        $this->assertSame(
            ['Élection sans candidat', 'Décision par consentement'],
            $restored->procedures,
        );
    }

    public function testPriorityTagsAndPhaseRoundTripThroughFrontmatter(): void {
        $card = new Card(
            id: 'pt1',
            title: 'Tâche',
            column: '02-En cours',
            description: 'corps',
            priority: 'haute',
            tags: ['infrastructure', 'urgent'],
            phase: 2,
        );

        $restored = Card::fromMarkdown($card->toYAMLFrontmatter() . "\n" . $card->description);

        $this->assertSame('haute', $restored->priority);
        $this->assertSame(['infrastructure', 'urgent'], $restored->tags);
        $this->assertSame(2, $restored->phase);
    }

    public function testStartDateRoundTripsThroughFrontmatter(): void {
        $card = new Card(
            id: 'sd1',
            title: 'Tâche datée',
            column: '02-En cours',
            description: 'corps',
            start_date: '2026-06-01',
            due_date: '2026-06-15',
        );

        $restored = Card::fromMarkdown($card->toYAMLFrontmatter() . "\n" . $card->description);

        $this->assertSame('2026-06-01', $restored->start_date);
        $this->assertSame('2026-06-15', $restored->due_date);
    }

    public function testToYAMLFrontmatterIncludesStartDate(): void {
        $card = new Card(
            id: 'sd2',
            title: 'T',
            column: '01-Backlog',
            start_date: '2026-06-02',
        );

        $parsed = Yaml::parse(trim($card->toYAMLFrontmatter(), "-\n"));

        $this->assertSame('2026-06-02', $parsed['start_date']);
    }

    public function testWithColumnChangesColumnAndPreservesEveryOtherField(): void {
        $card = new Card(
            id: 'wc1',
            title: 'Tâche',
            column: '01-Backlog',
            description: 'corps',
            created_at: new \DateTime('2026-06-05T10:00:00Z'),
            assignees: ['alain'],
            due_date: '2026-06-15',
            procedures: ['Décision par consentement'],
            priority: '2',
            tags: ['infra'],
            phase: 3,
            start_date: '2026-06-01',
        );

        $moved = $card->withColumn('02-En cours');

        $this->assertSame('02-En cours', $moved->column);
        $this->assertSame('wc1', $moved->id);
        $this->assertSame('Tâche', $moved->title);
        $this->assertSame('corps', $moved->description);
        $this->assertSame(['alain'], $moved->assignees);
        $this->assertSame('2026-06-15', $moved->due_date);
        $this->assertSame('2026-06-01', $moved->start_date);
        $this->assertSame(['Décision par consentement'], $moved->procedures);
        $this->assertSame('2', $moved->priority);
        $this->assertSame(['infra'], $moved->tags);
        $this->assertSame(3, $moved->phase);
        // Immutable: the original is untouched.
        $this->assertSame('01-Backlog', $card->column);
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

        $parsed = Yaml::parse(trim($yaml, "-\n"));

        $this->assertSame('2026-06-20', $parsed['due_date']);
        $this->assertStringContainsString("assignees:\n  - alain", $yaml);
    }
}
