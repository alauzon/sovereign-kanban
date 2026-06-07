<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

use DateTime;
use Ramsey\Uuid\Uuid;

/**
 * Immutable Comment value object.
 *
 * Comments live in a card's comments.md, one block per comment, delimited by
 * an HTML comment marker so bodies (which may contain Markdown, even ###
 * headers) round-trip safely. Each block carries a stable id so a single
 * comment can be edited or deleted.
 */
final class Comment {

	public function __construct(
		public readonly string $id,
		public readonly string $author,
		public readonly DateTime $created_at,
		public readonly string $body,
	) {
	}

	/**
	 * Create a comment authored now, with a fresh id.
	 */
	public static function create(string $author, string $body): self {
		return new self(Uuid::uuid4()->toString(), $author, new DateTime(), $body);
	}

	/**
	 * Serialize this comment as a delimited block for comments.md.
	 */
	public function toMarkdown(): string {
		$author = str_replace('"', '', $this->author);
		$iso = $this->created_at->format('Y-m-d\TH:i:s\Z');

		return "<!-- sk-comment id=\"{$this->id}\" author=\"{$author}\" date=\"{$iso}\" -->\n" . trim($this->body) . "\n";
	}

	/**
	 * Shape the comment for the JSON API.
	 *
	 * @return array{id: string, author: string, created_at: string, body: string}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'author' => $this->author,
			'created_at' => $this->created_at->format('Y-m-d\TH:i:s\Z'),
			'body' => $this->body,
		];
	}

	/**
	 * Parse all comments from a comments.md content string.
	 *
	 * The id attribute is optional: blocks written before ids existed get a
	 * stable id derived from their content, so edit/delete still target them.
	 *
	 * @return Comment[] In document order.
	 */
	public static function parseAll(string $content): array {
		$pattern = '/<!-- sk-comment (?:id="([^"]*)" )?author="([^"]*)" date="([^"]*)" -->\n?(.*?)(?=\n*<!-- sk-comment |\s*$)/s';
		if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
			return [];
		}

		$comments = [];
		foreach ($matches as $match) {
			$author = $match[2];
			$date = $match[3];
			$body = trim($match[4]);
			$id = ($match[1] ?? '') !== '' ? $match[1] : self::deriveId($author, $date, $body);
			$comments[] = new self(
				id: $id,
				author: $author,
				created_at: new DateTime($date !== '' ? $date : 'now'),
				body: $body,
			);
		}

		return $comments;
	}

	/**
	 * A stable id for a legacy comment that has no persisted id, derived from
	 * its content so it stays the same across reads.
	 */
	private static function deriveId(string $author, string $date, string $body): string {
		return substr(sha1($author . '|' . $date . '|' . $body), 0, 12);
	}
}
