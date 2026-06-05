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
