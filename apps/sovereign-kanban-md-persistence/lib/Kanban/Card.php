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
	) {
	}

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
		);
	}

	/**
	 * Rebuild a Card from a card.md file's content.
	 *
	 * Splits the YAML frontmatter (between --- markers) from the Markdown
	 * body; the body becomes the description.
	 */
	public static function fromMarkdown(string $content): self {
		if (preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $content, $matches)) {
			$frontmatter = Yaml::parse($matches[1]) ?? [];
			$body = ltrim($matches[2], "\r\n");
		} else {
			$frontmatter = [];
			$body = $content;
		}

		return new self(
			id: (string) ($frontmatter['id'] ?? ''),
			title: (string) ($frontmatter['title'] ?? ''),
			column: (string) ($frontmatter['column'] ?? ''),
			description: $body,
			created_at: self::parseCreatedAt($frontmatter['created_at'] ?? null),
			assignees: $frontmatter['assignees'] ?? [],
			due_date: self::parseDate($frontmatter['due_date'] ?? null),
			procedures: $frontmatter['procédures'] ?? [],
			priority: isset($frontmatter['priorité']) && $frontmatter['priorité'] !== '' ? (string) $frontmatter['priorité'] : null,
			tags: $frontmatter['étiquettes'] ?? [],
			phase: isset($frontmatter['phase']) && $frontmatter['phase'] !== '' ? (int) $frontmatter['phase'] : null,
			start_date: self::parseDate($frontmatter['start_date'] ?? null),
		);
	}

	/**
	 * Normalize a date value (YAML may give an ISO string or a Unix timestamp)
	 * to a plain 'Y-m-d' string, or null when unset.
	 *
	 * Shared by due_date and start_date.
	 */
	private static function parseDate(mixed $raw): ?string {
		if ($raw === null || $raw === '') {
			return null;
		}
		if (is_int($raw)) {
			// YAML date → midnight-UTC timestamp; format in UTC to keep the day.
			return gmdate('Y-m-d', $raw);
		}

		return substr((string) $raw, 0, 10);
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
			'excerpt' => $this->excerpt(),
		];
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
	 * @return string YAML frontmatter enclosed in --- markers
	 */
	public function toYAMLFrontmatter(): string {
		$frontmatter = [
			'id' => $this->id,
			'title' => $this->title,
			'column' => $this->column,
			'created_at' => $this->created_at->format('Y-m-d\TH:i:s\Z'),
		];

		if ($this->due_date !== null) {
			$frontmatter['due_date'] = $this->due_date;
		}

		if ($this->start_date !== null) {
			$frontmatter['start_date'] = $this->start_date;
		}

		if (!empty($this->assignees)) {
			$frontmatter['assignees'] = $this->assignees;
		}

		if (!empty($this->procedures)) {
			$frontmatter['procédures'] = $this->procedures;
		}

		if ($this->priority !== null) {
			$frontmatter['priorité'] = $this->priority;
		}

		if (!empty($this->tags)) {
			$frontmatter['étiquettes'] = $this->tags;
		}

		if ($this->phase !== null) {
			$frontmatter['phase'] = $this->phase;
		}

		$yaml = "---\n";
		foreach ($frontmatter as $key => $value) {
			if (is_array($value)) {
				$yaml .= "$key:\n";
				foreach ($value as $item) {
					$yaml .= "  - $item\n";
				}
			} else {
				$yaml .= "$key: $value\n";
			}
		}
		$yaml .= "---";

		return $yaml;
	}
}
