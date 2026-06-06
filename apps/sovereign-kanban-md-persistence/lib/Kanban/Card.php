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
			due_date: self::parseDueDate($frontmatter['due_date'] ?? null),
		);
	}

	/**
	 * Normalize a due_date (YAML may give an ISO string or a Unix timestamp)
	 * to a plain 'Y-m-d' string, or null when unset.
	 */
	private static function parseDueDate(mixed $raw): ?string {
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
	 * @return array{id: string, title: string, column: string, assignees: list<string>}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'title' => $this->title,
			'column' => $this->column,
			'due_date' => $this->due_date,
			'assignees' => array_values($this->assignees),
		];
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

		if (!empty($this->assignees)) {
			$frontmatter['assignees'] = $this->assignees;
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
