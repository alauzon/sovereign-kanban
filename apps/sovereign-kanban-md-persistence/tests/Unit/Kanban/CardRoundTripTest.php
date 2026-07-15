<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Kanban;

use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip conformance for the card.md format.
 *
 * The sovereignty claim is: your data is a plain .md file you can open, edit and
 * keep in any tool. That claim is only true if the file survives a round-trip —
 * write it, read it back, write it again, and get the same card. This suite is
 * the executable form of that claim; the spec (documentation/10-card-md-format.md)
 * is true exactly when this file is green.
 *
 * Why these cases and not others: they were MEASURED against the current code,
 * not imagined. Each one is a real defect reproduced, in the reproduce-then-fix
 * discipline of documentation/09-testing-playbook.md.
 *
 * Root cause shared by most of them: the writer (Card::toYAMLFrontmatter) is a
 * hand-rolled string concatenation, while the reader (Card::fromMarkdown) is a
 * real YAML parser. An emitter that does not escape cannot round-trip through a
 * parser that does interpret. The fix is to emit with Symfony\Yaml::dump, not to
 * patch the cases one by one.
 *
 * Status on the day it was written (2026-07-15): RED, by design.
 * Not one of these titles exists on the production instance yet — 286 real cards,
 * zero with a colon. These are latent defects, not active ones. The controller
 * only trim()s the title, so nothing prevents them; we have been lucky.
 *
 * @group sovereign-kanban
 * @group card-md-format
 */
final class CardRoundTripTest extends TestCase {

    /**
     * Serialize exactly as FileCardRepository::serialize does.
     *
     * Kept identical on purpose: a round-trip test that serializes differently
     * from production tests a format nobody writes.
     */
    private function serialize(Card $card): string {
        return $card->toYAMLFrontmatter() . "\n\n" . $card->description;
    }

    private function roundTrip(Card $card): Card {
        return Card::fromMarkdown($this->serialize($card));
    }

    /**
     * Titles a member could plausibly type, each breaking the format differently.
     *
     * @return array<string, array{0: string}>
     */
    public static function hostileTitleProvider(): array {
        return [
            // Symfony\Yaml throws: "A colon cannot be used in an unquoted mapping value".
            // The likeliest title form there is; one card would break the whole board listing.
            'colon'            => ['Corriger: le bug du partage'],
            // Throws: "Unexpected token" — YAML reads [ as a flow sequence.
            'brackets'         => ['[SdP] réunion du mardi'],
            // Throws: "Unexpected token" — YAML reads { as a flow mapping.
            'braces'           => ['{{ modèle }} à définir'],
            // Does not throw. Worse: YAML reads # as a comment and the title
            // comes back as the empty string. Silent, total loss.
            'leading hash'     => ['#urgent revoir la palette'],
            // Throws: "Unable to parse" — the second line is not a mapping.
            'newline'          => ["Titre\nsur deux lignes"],
            // Silently trimmed by the YAML parser.
            'trailing space'   => ['Réunion '],
        ];
    }

    /**
     * A title must survive being written and read back, whatever it contains.
     *
     * @dataProvider hostileTitleProvider
     */
    public function testTitleSurvivesRoundTrip(string $title): void {
        $card = new Card(id: 'rt-title', title: $title, column: '01-Backlog');

        $this->assertSame(
            $title,
            $this->roundTrip($card)->title,
            'A card title must round-trip unchanged. It is written by the user, not by us.',
        );
    }

    /**
     * A tag is a string. It must not become a data structure.
     *
     * Measured: ['prio: haute'] comes back as [['prio' => 'haute']] — the colon
     * makes the YAML parser read the list item as a mapping. The card's type
     * changes underneath the user.
     */
    public function testTagWithColonStaysAString(): void {
        $card = new Card(id: 'rt-tag', title: 'T', column: 'C', tags: ['prio: haute']);

        $this->assertSame(['prio: haute'], $this->roundTrip($card)->tags);
    }

    /**
     * created_at must denote the same instant after a round-trip.
     *
     * Measured: a card created at 10:00 in Montréal (-04:00) reads back as
     * 10:00 UTC — the instant moves by 4 hours. Cause: toYAMLFrontmatter formats
     * with 'Y-m-d\TH:i:s\Z', where \Z is a LITERAL Z, not a conversion to UTC.
     * The offset is dropped and a false UTC marker is stamped on local time.
     * Every card created outside UTC carries this.
     */
    public function testCreatedAtKeepsItsInstantAcrossTimezones(): void {
        $card = new Card(
            id: 'rt-tz',
            title: 'T',
            column: 'C',
            created_at: new \DateTime('2026-06-05T10:00:00-04:00'),
        );

        $this->assertSame(
            $card->created_at->getTimestamp(),
            $this->roundTrip($card)->created_at->getTimestamp(),
            'created_at must denote the same instant; \Z is a literal, not a UTC conversion.',
        );
    }

    /**
     * A due date with a time must keep its time.
     *
     * Measured before the fix: '2026-07-20 14:30' came back '2026-07-20' —
     * parseDate() did substr($raw, 0, 10). Escalated by camille-ux-nextcloud
     * for the Deck migration: Deck due dates carry a time, and the truncation
     * lost it for good, since the file — not a cache — is the record.
     *
     * Decided by Alain on 2026-07-15: due dates are date-times. The canonical
     * form is 'Y-m-d\TH:i'; a date with no known time stays 'Y-m-d'. A time is
     * never invented and never dropped.
     */
    public function testDueDateInCanonicalFormRoundTripsUnchanged(): void {
        $card = new Card(id: 'rt-due', title: 'T', column: 'C', due_date: '2026-07-20T14:30');

        $this->assertSame('2026-07-20T14:30', $this->roundTrip($card)->due_date);
    }

    /**
     * A non-canonical date-time is normalized, and the time survives it.
     *
     * Normalizing on read is allowed; losing the time is not. The two are easy
     * to confuse — this test pins the difference.
     */
    public function testDueDateWithASpaceIsNormalizedWithoutLosingItsTime(): void {
        $card = new Card(id: 'rt-due-sp', title: 'T', column: 'C', due_date: '2026-07-20 14:30');

        $this->assertSame('2026-07-20T14:30', $this->roundTrip($card)->due_date);
    }

    /**
     * A date with no time must not be given one.
     */
    public function testDateWithoutTimeStaysWithoutTime(): void {
        $card = new Card(id: 'rt-due-d', title: 'T', column: 'C', due_date: '2026-07-20');

        $this->assertSame('2026-07-20', $this->roundTrip($card)->due_date);
    }

    /**
     * Frontmatter keys we do not know must be preserved, not eaten.
     *
     * This is the sovereignty claim itself. The README says the cards are
     * "readable in Obsidian" — but Obsidian writes `aliases`, `cssclass`, `tags`;
     * a user writes whatever they need. Today fromMarkdown() picks the known keys
     * and drops the rest, so the next write by the app deletes what the user
     * added in their own file. An app that silently eats what it does not
     * understand does not host your data. It borrows it.
     */
    public function testUnknownFrontmatterKeysArePreserved(): void {
        $md = <<<MD
        ---
        id: rt-unknown
        title: Ma carte
        column: 01-Backlog
        aliases:
          - ancien-nom
        cssclass: kanban-large
        ---

        Le corps.
        MD;

        $rewritten = Card::fromMarkdown($md)->toYAMLFrontmatter();

        $this->assertStringContainsString('aliases', $rewritten, 'A key the user added must survive our rewrite.');
        $this->assertStringContainsString('cssclass', $rewritten, 'A key the user added must survive our rewrite.');
    }

    /**
     * Two round-trips must be identical to one.
     *
     * The property that matters in production: the app rewrites card.md on every
     * move, every rename, every edit. If serialize(parse(x)) != x, each pass
     * degrades the file a little more. This asserts the format has a fixed point.
     *
     * The card is built in canonical form on purpose. Normalizing a hand-written
     * value on first read is allowed (see the due_date tests); what must never
     * happen is a file the app wrote itself changing again on the next write.
     */
    public function testSerializationIsStableAcrossRepeatedWrites(): void {
        $card = new Card(
            id: 'rt-stable',
            title: 'Réunion du conseil',
            column: '02-En cours',
            description: "Ordre du jour\n\n---\n\nDivers",
            created_at: new \DateTime('2026-06-05T10:00:00-04:00'),
            assignees: ['steve'],
            due_date: '2026-07-20T14:30',
            tags: ['prio: haute'],
        );

        $once = $this->serialize($card);
        $twice = $this->serialize(Card::fromMarkdown($once));

        $this->assertSame($once, $twice, 'Rewriting a card must not keep changing the file.');
    }
}
