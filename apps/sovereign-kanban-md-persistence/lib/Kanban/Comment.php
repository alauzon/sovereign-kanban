<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

use DateTime;

/**
 * Immutable Comment value object.
 *
 * Comments live in a card's comments.md, one block per comment, delimited by
 * an HTML comment marker so bodies (which may contain Markdown, even ###
 * headers) round-trip safely.
 */
final class Comment {

	public function __construct(
		public readonly string $author,
		public readonly DateTime $created_at,
		public readonly string $body,
	) {
	}

	/**
	 * Create a comment authored now.
	 */
	public static function create(string $author, string $body): self {
		return new self($author, new DateTime(), $body);
	}

	/**
	 * Serialize this comment as a delimited block for comments.md.
	 */
	public function toMarkdown(): string {
		$author = str_replace('"', '', $this->author);
		$iso = $this->created_at->format('Y-m-d\TH:i:s\Z');

		return "<!-- sk-comment author=\"{$author}\" date=\"{$iso}\" -->\n" . trim($this->body) . "\n";
	}

	/**
	 * Shape the comment for the JSON API.
	 *
	 * @return array{author: string, created_at: string, body: string}
	 */
	public function toArray(): array {
		return [
			'author' => $this->author,
			'created_at' => $this->created_at->format('Y-m-d\TH:i:s\Z'),
			'body' => $this->body,
		];
	}

	/**
	 * Parse all comments from a comments.md content string.
	 *
	 * @return Comment[] In document order.
	 */
	public static function parseAll(string $content): array {
		$pattern = '/<!-- sk-comment author="([^"]*)" date="([^"]*)" -->\n?(.*?)(?=\n*<!-- sk-comment |\s*$)/s';
		if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
			return [];
		}

		$comments = [];
		foreach ($matches as $match) {
			$comments[] = new self(
				author: $match[1],
				created_at: new DateTime($match[2] !== '' ? $match[2] : 'now'),
				body: trim($match[3]),
			);
		}

		return $comments;
	}
}
