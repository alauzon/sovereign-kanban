<?php

/**
 * @file
 * Card value object for Sovereign Kanban.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Haiku 4.5)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

use Ramsey\Uuid\Uuid;
use DateTime;
use Symfony\Component\Yaml\Yaml;

/**
 * Immutable Card value object.
 *
 * Represents a Kanban card with stable UUID and YAML frontmatter.
 */
final class Card {

	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $column,
		public readonly string $description = '',
		public readonly DateTime $created_at = new DateTime(),
		public readonly array $assignees = [],
		public readonly ?string $due_date = null,
		public readonly array $procedures = [],
		public readonly ?string $priority = null,
		public readonly array $tags = [],
		public readonly ?int $phase = null,
		public readonly ?string $start_date = null,
		// ISO instant when the card was marked done, or null while it is open
		// (Alain, 2026-07-19: completed_at rather than a bare boolean, to keep the
		// "when").
		public readonly ?string $completed_at = null,
		// uid of whoever created the card, written once at creation (Alain,
		// 2026-07-19). Never rewritten by an edit.
		public readonly ?string $author = null,
		// Card colour (Alain, 2026-07-19): a hex string from the board's palette,
		// or null. Shown as a left band on the tile.
		public readonly ?string $color = null,
		// ISO instant when the card was archived, or null while it is active
		// (Alain, 2026-07-19). Archived cards are hidden from the board unless the
		// « Afficher les archivées » toggle is on.
		public readonly ?string $archived = null,
		// Typed links to other cards (Alain, 2026-07-19): a list of
		// ['type' => child|parent|depends|required|related, 'card' => <id>]. The
		// reciprocal is stored on the other card; only the id is kept here, never
		// the title — a rename must not go stale.
		public readonly array $relations = [],
		public readonly ?string $linked_board = null,
		public readonly array $extra = [],
	) {
	}

	/**
	 * The frontmatter keys this app understands.
	 *
	 * Everything else read from a card.md lands in $extra and is written back
	 * untouched. The vocabulary is closed; the file is not. See
	 * documentation/10-card-md-format.md.
	 */
	private const KNOWN_KEYS = [
		'id', 'title', 'column', 'created_at', 'assignees', 'due_date',
		'start_date', 'procedures', 'priority', 'tags', 'phase', 'completed_at',
		'author', 'color', 'archived', 'relations', 'linked_board',
		// Legacy French spellings: read, never written. Files created before
		// 2026-07-15 carry them; they migrate silently on the next app write.
		'procédures', 'priorité', 'étiquettes',
	];

	/**
	 * Relation types and their reciprocal (Alain, 2026-07-19). Stored in English;
	 * the UI translates. Adding A --child--> B stores B --parent--> A, and so on.
	 * 'related' is symmetric. 'depends' (a besoin de) ↔ 'required' (nécessaire pour).
	 */
	public const RELATION_RECIPROCAL = [
		'child' => 'parent',
		'parent' => 'child',
		'depends' => 'required',
		'required' => 'depends',
		'related' => 'related',
	];

	/**
	 * Create a new card with generated UUID.
	 */
	public static function create(string $title, string $column): self {
		return new self(
			id: Uuid::uuid4()->toString(),
			title: $title,
			column: $column,
		);
	}

	/**
	 * Return a copy moved to another column, preserving every other field.
	 *
	 * Used when a card moves between columns or a column is renamed, so that
	 * metadata (due/start dates, assignees, procedures, priority, tags, phase)
	 * is never dropped during the resync.
	 */
	public function withColumn(string $column): self {
		return new self(
			id: $this->id,
			title: $this->title,
			column: $column,
			description: $this->description,
			created_at: $this->created_at,
			assignees: $this->assignees,
			due_date: $this->due_date,
			procedures: $this->procedures,
			priority: $this->priority,
			tags: $this->tags,
			phase: $this->phase,
			start_date: $this->start_date,
			completed_at: $this->completed_at,
			author: $this->author,
			color: $this->color,
			archived: $this->archived,
			relations: $this->relations,
			linked_board: $this->linked_board,
			extra: $this->extra,
		);
	}

	/**
	 * Return a copy with the relations list replaced, every other field kept.
	 *
	 * Relations are managed on their own (add/remove with a reciprocal on the
	 * other card); this is the single immutable setter the repository uses.
	 *
	 * @param list<array{type: string, card: string}> $relations
	 */
	public function withRelations(array $relations): self {
		return new self(
			id: $this->id,
			title: $this->title,
			column: $this->column,
			description: $this->description,
			created_at: $this->created_at,
			assignees: $this->assignees,
			due_date: $this->due_date,
			procedures: $this->procedures,
			priority: $this->priority,
			tags: $this->tags,
			phase: $this->phase,
			start_date: $this->start_date,
			completed_at: $this->completed_at,
			author: $this->author,
			color: $this->color,
			archived: $this->archived,
			relations: array_values($relations),
			linked_board: $this->linked_board,
			extra: $this->extra,
		);
	}

	/**
	 * Rebuild a Card from a card.md file's content.
	 *
	 * Splits the YAML frontmatter (between --- markers) from the Markdown
	 * body; the body becomes the description. Frontmatter keys this app does
	 * not know are kept in $extra so that writing the card back does not
	 * destroy them.
	 */
	public static function fromMarkdown(string $content): self {
		if (preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $content, $matches)) {
			$frontmatter = Yaml::parse($matches[1]) ?? [];
			$body = ltrim($matches[2], "\r\n");
		} else {
			$frontmatter = [];
			$body = $content;
		}

		$procedures = $frontmatter['procedures'] ?? $frontmatter['procédures'] ?? [];
		$priority = $frontmatter['priority'] ?? $frontmatter['priorité'] ?? null;
		$tags = $frontmatter['tags'] ?? $frontmatter['étiquettes'] ?? [];

		return new self(
			id: (string) ($frontmatter['id'] ?? ''),
			title: (string) ($frontmatter['title'] ?? ''),
			column: (string) ($frontmatter['column'] ?? ''),
			description: $body,
			created_at: self::parseCreatedAt($frontmatter['created_at'] ?? null),
			assignees: $frontmatter['assignees'] ?? [],
			due_date: self::normalizeDate($frontmatter['due_date'] ?? null),
			procedures: $procedures,
			priority: ($priority !== null && $priority !== '') ? (string) $priority : null,
			tags: $tags,
			phase: isset($frontmatter['phase']) && $frontmatter['phase'] !== '' ? (int) $frontmatter['phase'] : null,
			start_date: self::normalizeDate($frontmatter['start_date'] ?? null),
			completed_at: (isset($frontmatter['completed_at']) && $frontmatter['completed_at'] !== '') ? (string) $frontmatter['completed_at'] : null,
			author: (isset($frontmatter['author']) && $frontmatter['author'] !== '') ? (string) $frontmatter['author'] : null,
			color: (isset($frontmatter['color']) && $frontmatter['color'] !== '') ? (string) $frontmatter['color'] : null,
			archived: (isset($frontmatter['archived']) && $frontmatter['archived'] !== '') ? (string) $frontmatter['archived'] : null,
			relations: self::normalizeRelations($frontmatter['relations'] ?? []),
			linked_board: (isset($frontmatter["linked_board"]) && $frontmatter["linked_board"] !== "") ? (string) $frontmatter["linked_board"] : null,
			extra: array_diff_key($frontmatter, array_flip(self::KNOWN_KEYS)),
		);
	}

	/**
	 * Normalize a due/start date, preserving its time when it has one.
	 *
	 * Accepted forms: 'Y-m-d' (no time known) or 'Y-m-d\TH:i' (date-time).
	 * A time is never invented and never dropped — the file IS the record, so
	 * a truncation here loses the time for good.
	 *
	 * YAML hands us an int when the value was written unquoted (legacy files);
	 * midnight then means "no time was recorded", anything else is a real time.
	 *
	 * Public and static because it is the ONE place a date is normalized:
	 * CardController must call this rather than reimplement it. It did, with a
	 * substr($value, 0, 10) of its own, which silently truncated every time the
	 * browser sent one — a bug that survived a green suite because the fix had
	 * been applied here only. Duplicated normalization is how that happens.
	 *
	 * Shared by due_date and start_date.
	 */
	/**
	 * Keep only well-formed relation entries when reading a file: each must be a
	 * map with a non-empty 'type' and 'card'. Protects the card from a hand-edited
	 * or malformed relations list; the controller is the strict guard on write.
	 *
	 * @return list<array{type: string, card: string}>
	 */
	private static function normalizeRelations(mixed $raw): array {
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		foreach ($raw as $r) {
			if (is_array($r)
				&& isset($r['type'], $r['card'])
				&& is_scalar($r['type']) && (string) $r['type'] !== ''
				&& is_scalar($r['card']) && (string) $r['card'] !== '') {
				$out[] = ['type' => (string) $r['type'], 'card' => (string) $r['card']];
			}
		}
		return $out;
	}

	public static function normalizeDate(mixed $raw): ?string {
		if ($raw === null || $raw === '') {
			return null;
		}

		if (is_int($raw)) {
			return ($raw % 86400 === 0) ? gmdate('Y-m-d', $raw) : gmdate('Y-m-d\TH:i', $raw);
		}

		$value = trim((string) $raw);
		if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $value, $m)) {
			return isset($m[2]) ? $m[1] . 'T' . $m[2] : $m[1];
		}

		return $value;
	}

	/**
	 * Whether a date-input string is a real calendar date the app may store.
	 *
	 * The write path (CardController) MUST call this before normalizeDate:
	 * normalizeDate is tolerant by design (it must read back files that already
	 * hold a bad date, to let them be seen and fixed), so it cannot be the guard.
	 * Found 2026-07-18: Chrome's datetime-local accepts a 6-digit year on manual
	 * entry ("202607-07-19"), and the tolerant normalize stored it verbatim.
	 * Normalizing is not validating — same lesson as the D5 assignee hole.
	 *
	 * Empty is valid (it clears the date). Otherwise the string must be a real
	 * 'Y-m-d' or 'Y-m-d\TH:i' — four-digit year, a date that exists on the
	 * calendar, and an in-range time.
	 */
	public static function isValidDateInput(?string $raw): bool {
		if ($raw === null || $raw === '') {
			return true;
		}
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?$/', trim($raw), $m)) {
			return false;
		}
		if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			return false;
		}

		return !isset($m[4]) || ((int) $m[4] <= 23 && (int) $m[5] <= 59);
	}

	/**
	 * Parse a created_at value that YAML may give us as an ISO string or,
	 * for timestamps, as a Unix epoch integer.
	 */
	private static function parseCreatedAt(mixed $raw): DateTime {
		if ($raw === null || $raw === '') {
			return new DateTime();
		}
		if (is_int($raw)) {
			return (new DateTime())->setTimestamp($raw);
		}

		return new DateTime((string) $raw);
	}

	/**
	 * Shape the card for the JSON API (frontend consumption).
	 *
	 * @return array{id: string, title: string, column: string, due_date: ?string, start_date: ?string, created_at: string, assignees: list<string>, procedures: list<string>, priority: ?string, tags: list<string>, phase: ?int, excerpt: string}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'title' => $this->title,
			'column' => $this->column,
			'due_date' => $this->due_date,
			'start_date' => $this->start_date,
			'created_at' => $this->created_at->format('Y-m-d\TH:i:s\Z'),
			'assignees' => array_values($this->assignees),
			'procedures' => array_values($this->procedures),
			'priority' => $this->priority,
			'tags' => array_values($this->tags),
			'phase' => $this->phase,
			'completed_at' => $this->completed_at,
			'author' => $this->author,
			'color' => $this->color,
			'archived' => $this->archived,
			'relations' => $this->relations,
			'linked_board' => $this->linked_board,
			'checklist' => $this->checklist(),
			'excerpt' => $this->excerpt(),
		];
	}

	/**
	 * Count the Markdown checkboxes in the description — Deck's "done/total"
	 * badge, read straight from the body (Alain, 2026-07-18: the checklist is the
	 * '- [ ]'/'- [x]' items already in the .md, nothing extra to store).
	 *
	 * @return array{done: int, total: int}
	 */
	public function checklist(): array {
		preg_match_all('/^\s*[-*]\s+\[([ xX])\]/m', $this->description, $m);
		$marks = $m[1];
		$done = count(array_filter($marks, static fn (string $c): bool => strtolower($c) === 'x'));
		return ['done' => $done, 'total' => count($marks)];
	}

	/**
	 * A short, plain-text preview of the description for the card tile.
	 *
	 * Strips the common Markdown noise (fences, headings, emphasis, links,
	 * table pipes), collapses whitespace, and truncates with an ellipsis.
	 *
	 * @param int $length Maximum length of the returned excerpt.
	 *
	 * @return string Plain-text excerpt, or '' when there is no description.
	 */
	public function excerpt(int $length = 140): string {
		$text = $this->description;
		$text = preg_replace('/```.*?```/s', ' ', $text);
		$text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text);
		$text = preg_replace('/^#{1,6}\s+/m', '', $text);
		// Drop table separator rows (|---|:--:|) before stripping the markers.
		$text = preg_replace('/^[\s|:\-]+$/m', ' ', $text);
		$text = preg_replace('/[*_`>#|~\[\]]+/', ' ', $text);
		$text = preg_replace('/\s+/', ' ', $text);
		$text = trim($text);

		if (mb_strlen($text) <= $length) {
			return $text;
		}

		return mb_substr($text, 0, $length - 1) . '…';
	}

	/**
	 * Serialize card as YAML frontmatter.
	 *
	 * Emitted with Yaml::dump — the inverse of the Yaml::parse used to read it.
	 * The previous hand-rolled concatenation could not escape, so any title
	 * holding ':', '[', '{' or a leading '#' produced a file the parser then
	 * refused or misread. An emitter that does not escape cannot round-trip
	 * through a parser that interprets. See documentation/10-card-md-format.md.
	 *
	 * Keys are written in English. French keys ('procédures', 'priorité',
	 * 'étiquettes') are still accepted on read for the files already on disk.
	 *
	 * @return string YAML frontmatter enclosed in --- markers
	 */
	public function toYAMLFrontmatter(): string {
		$frontmatter = [
			'id' => $this->id,
			'title' => $this->title,
			'column' => $this->column,
			'created_at' => $this->createdAtUtc(),
		];

		if ($this->due_date !== null) {
			$frontmatter['due_date'] = $this->due_date;
		}

		if ($this->start_date !== null) {
			$frontmatter['start_date'] = $this->start_date;
		}

		if (!empty($this->assignees)) {
			$frontmatter['assignees'] = array_values($this->assignees);
		}

		if (!empty($this->procedures)) {
			$frontmatter['procedures'] = array_values($this->procedures);
		}

		if ($this->priority !== null) {
			$frontmatter['priority'] = $this->priority;
		}

		if (!empty($this->tags)) {
			$frontmatter['tags'] = array_values($this->tags);
		}

		if ($this->phase !== null) {
			$frontmatter['phase'] = $this->phase;
		}

		if ($this->completed_at !== null) {
			$frontmatter['completed_at'] = $this->completed_at;
		}

		if ($this->author !== null) {
			$frontmatter['author'] = $this->author;
		}

		if ($this->color !== null) {
			$frontmatter['color'] = $this->color;
		}

		if ($this->archived !== null) {
			$frontmatter['archived'] = $this->archived;
		}


		if ($this->linked_board !== null) {

			$frontmatter['linked_board'] = $this->linked_board;

		}

		if (!empty($this->relations)) {
			$frontmatter['relations'] = array_values($this->relations);
		}

		// Keys we do not understand are written back untouched: the vocabulary
		// is closed, the file is not.
		foreach ($this->extra as $key => $value) {
			$frontmatter[$key] = $value;
		}

		return "---\n" . Yaml::dump($frontmatter, 4, 2) . '---';
	}

	/**
	 * created_at as a real UTC instant.
	 *
	 * The previous format string was 'Y-m-d\TH:i:s\Z', where \Z is a LITERAL Z,
	 * not a conversion: a card created at 10:00 in Montréal was stamped
	 * '10:00Z' and read back four hours off. Converting first makes the Z true.
	 */
	private function createdAtUtc(): string {
		return (clone $this->created_at)
			->setTimezone(new \DateTimeZone('UTC'))
			->format('Y-m-d\TH:i:s\Z');
	}
}
