<?php

/**
 * @file
 * FileCardRepository — persistence for cards via a Storage abstraction.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

use OCA\SovereignKanbanMdPersistence\Storage\Storage;

/**
 * Repository for cards, scoped to one board's Storage.
 *
 * Layout (relative to the board): {column}/{uuid}-{slug}/card.md
 * The Storage implementation decides whether that hits the raw filesystem
 * (tests) or the Nextcloud Files API (production, cache-synced).
 */
final class FileCardRepository {

	public function __construct(
		private readonly Storage $storage,
	) {
	}

	/**
	 * Save a card (creates {column}/{uuid}-{slug}/card.md).
	 */
	public function save(Card $card): void {
		$slug = str_replace(' ', '-', $card->title);
		$cardDir = $card->column . '/' . $card->id . '-' . $slug;
		$this->storage->write($cardDir . '/card.md', $this->serialize($card));
	}

	/**
	 * List all cards grouped by clean column name (NN- prefix stripped).
	 *
	 * @return array<string, Card[]>
	 */
	public function listByColumn(): array {
		$result = [];
		foreach ($this->storage->childDirectories('') as $columnFolder) {
			$cleanName = preg_replace('/^\d+-/', '', $columnFolder);
			$cards = [];
			foreach ($this->storage->childDirectories($columnFolder) as $cardFolder) {
				$file = $columnFolder . '/' . $cardFolder . '/card.md';
				if ($this->storage->exists($file)) {
					$cards[] = Card::fromMarkdown($this->storage->read($file));
				}
			}
			$result[$cleanName] = $cards;
		}

		return $result;
	}

	/**
	 * Resolve a clean column name to its NN-prefixed folder name, or null.
	 */
	public function resolveColumnFolder(string $cleanName): ?string {
		foreach ($this->storage->childDirectories('') as $columnFolder) {
			if (preg_replace('/^\d+-/', '', $columnFolder) === $cleanName) {
				return $columnFolder;
			}
		}

		return null;
	}

	/**
	 * Find a card by id across all columns, or null if absent.
	 */
	public function findById(string $cardId): ?Card {
		$dir = $this->findCardDirAnywhere($cardId);

		return ($dir !== null && $this->storage->exists($dir . '/card.md'))
			? Card::fromMarkdown($this->storage->read($dir . '/card.md'))
			: null;
	}

	/**
	 * Last-modified timestamp of a card's card.md, or null if not found — for the
	 * card's "Modifié" summary (Alain, 2026-07-19).
	 */
	public function mtimeOf(string $cardId): ?int {
		$dir = $this->findCardDirAnywhere($cardId);

		return ($dir !== null) ? $this->storage->mtime($dir . '/card.md') : null;
	}

	/**
	 * Rewrite an existing card's card.md in place (keeps its directory).
	 */
	public function update(Card $card): void {
		$dir = $this->findCardDir($card->column, $card->id);
		if ($dir === null) {
			throw new \RuntimeException('Card not found: ' . $card->id);
		}

		$this->storage->write($dir . '/card.md', $this->serialize($card));
	}

	/**
	 * Move a card to another column, keeping its UUID and resyncing the
	 * card.md 'column' frontmatter to the new folder.
	 */
	public function moveCard(string $cardId, string $fromColumn, string $toColumn): void {
		$fromDir = $this->findCardDir($fromColumn, $cardId);
		if ($fromDir === null) {
			throw new \Exception("Card not found in column: $fromColumn");
		}

		$toDir = $toColumn . '/' . basename($fromDir);
		$this->storage->move($fromDir, $toDir);

		$file = $toDir . '/card.md';
		if ($this->storage->exists($file)) {
			$card = Card::fromMarkdown($this->storage->read($file));
			$this->storage->write($file, $this->serialize($card->withColumn($toColumn)));
		}
	}

	/**
	 * Delete a card known to be in a given column.
	 */
	public function delete(string $cardId, string $column): void {
		$dir = $this->findCardDir($column, $cardId);
		if ($dir !== null) {
			$this->storage->delete($dir);
		}
	}

	/**
	 * Delete a card by id, wherever it lives.
	 */
	public function deleteById(string $cardId): void {
		$dir = $this->findCardDirAnywhere($cardId);
		if ($dir !== null) {
			$this->storage->delete($dir);
		}
	}

	/**
	 * Append a comment to a card's comments.md.
	 */
	public function addComment(string $cardId, Comment $comment): void {
		$dir = $this->findCardDirAnywhere($cardId);
		if ($dir === null) {
			throw new \RuntimeException('Card not found: ' . $cardId);
		}

		$file = $dir . '/comments.md';
		$existing = $this->storage->exists($file) ? rtrim($this->storage->read($file), "\n") : '';
		$separator = $existing === '' ? '' : "\n\n";
		$this->storage->write($file, $existing . $separator . $comment->toMarkdown());
	}

	/**
	 * List a card's comments in document order (empty if none).
	 *
	 * @return Comment[]
	 */
	public function listComments(string $cardId): array {
		$dir = $this->findCardDirAnywhere($cardId);
		if ($dir === null) {
			return [];
		}

		$file = $dir . '/comments.md';

		return $this->storage->exists($file) ? Comment::parseAll($this->storage->read($file)) : [];
	}

	/**
	 * Cheap count of a card's comments for the tile badge (Alain, 2026-07-19):
	 * counts the '<!-- sk-comment ' markers without parsing each comment.
	 */
	public function countComments(string $cardId): int {
		$dir = $this->findCardDirAnywhere($cardId);
		if ($dir === null) {
			return 0;
		}

		$file = $dir . '/comments.md';

		return $this->storage->exists($file)
			? substr_count($this->storage->read($file), '<!-- sk-comment ')
			: 0;
	}

	/**
	 * Append one event to a card's sovereign activity journal (Alain, 2026-07-19,
	 * option C): activity.jsonl next to card.md, one JSON object per line,
	 * append-only. The history lives IN the card's folder — it travels with the
	 * card, is never pruned by Nextcloud, and records what changed, not just when.
	 *
	 * The timestamp is stamped here in UTC; callers pass only the semantic event.
	 *
	 * @param string      $cardId Card whose journal to append to.
	 * @param string      $action Event verb: created|updated|moved|commented|done|reopened.
	 * @param string|null $actor  uid of whoever acted, or null when unknown.
	 * @param array       $detail Action-specific payload (changed fields, from/to, …).
	 */
	public function appendActivity(string $cardId, string $action, ?string $actor, array $detail = []): void {
		$dir = $this->findCardDirAnywhere($cardId);
		if ($dir === null) {
			return;
		}

		$event = [
			'ts' => gmdate('Y-m-d\TH:i:s\Z'),
			'actor' => $actor,
			'action' => $action,
			'detail' => $detail,
		];
		$line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$file = $dir . '/activity.jsonl';
		$existing = $this->storage->exists($file) ? rtrim($this->storage->read($file), "\n") : '';
		$this->storage->write($file, $existing === '' ? $line . "\n" : $existing . "\n" . $line . "\n");
	}

	/**
	 * Read a card's activity journal in chronological order (oldest first).
	 *
	 * Malformed lines are skipped rather than aborting the read — the journal is
	 * a record to be shown, never a gate. Returns [] when the card has no journal.
	 *
	 * @return array<int, array{ts: string, actor: ?string, action: string, detail: array}>
	 */
	public function listActivity(string $cardId): array {
		$dir = $this->findCardDirAnywhere($cardId);
		if ($dir === null) {
			return [];
		}

		$file = $dir . '/activity.jsonl';
		if (!$this->storage->exists($file)) {
			return [];
		}

		$events = [];
		foreach (explode("\n", trim($this->storage->read($file))) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$decoded = json_decode($line, true);
			if (is_array($decoded) && isset($decoded['action'])) {
				$events[] = $decoded;
			}
		}

		return $events;
	}

	/**
	 * Add a typed relation between two cards, storing the reciprocal on the target
	 * (Alain, 2026-07-19): A --child--> B also writes B --parent--> A. Idempotent —
	 * an identical link is not duplicated. No self-relation.
	 *
	 * @return bool True if both cards exist and the type is known; false otherwise.
	 */
	public function addRelation(string $cardId, string $targetId, string $type): bool {
		if ($cardId === $targetId) {
			return false;
		}
		$reciprocal = Card::RELATION_RECIPROCAL[$type] ?? null;
		if ($reciprocal === null) {
			return false;
		}
		$a = $this->findById($cardId);
		$b = $this->findById($targetId);
		if ($a === null || $b === null) {
			return false;
		}

		$this->update($this->withRelationAdded($a, $type, $targetId));
		$this->update($this->withRelationAdded($b, $reciprocal, $cardId));
		return true;
	}

	/**
	 * Remove every relation between two cards, on both sides.
	 *
	 * @return bool True if the source card exists (the target may already be gone).
	 */
	public function removeRelation(string $cardId, string $targetId): bool {
		$a = $this->findById($cardId);
		if ($a === null) {
			return false;
		}
		$this->update($this->withRelationRemoved($a, $targetId));

		$b = $this->findById($targetId);
		if ($b !== null) {
			$this->update($this->withRelationRemoved($b, $cardId));
		}
		return true;
	}

	/**
	 * Resolve a card's relations for display: each entry gains the target's title
	 * and done state, looked up live so a rename never goes stale. A relation whose
	 * target no longer exists keeps a null title (a dangling link, shown as such).
	 *
	 * @return list<array{type: string, card: string, title: ?string, done: bool}>
	 */
	public function resolveRelations(Card $card): array {
		return array_map(function (array $r): array {
			$target = $this->findById($r['card']);
			return [
				'type' => $r['type'],
				'card' => $r['card'],
				'title' => $target?->title,
				'done' => $target !== null && $target->completed_at !== null,
			];
		}, $card->relations);
	}

	/**
	 * A copy of the card with one relation added, unless an identical one exists.
	 */
	private function withRelationAdded(Card $card, string $type, string $target): Card {
		foreach ($card->relations as $r) {
			if ($r['card'] === $target && $r['type'] === $type) {
				return $card;
			}
		}
		return $card->withRelations(array_merge($card->relations, [['type' => $type, 'card' => $target]]));
	}

	/**
	 * A copy of the card with every relation to the given target removed.
	 */
	private function withRelationRemoved(Card $card, string $target): Card {
		return $card->withRelations(array_filter(
			$card->relations,
			static fn (array $r): bool => $r['card'] !== $target,
		));
	}

	/**
	 * List a card's attachments (Alain, 2026-07-19): the files in its attachments/
	 * subfolder ARE the list — folder-as-truth, nothing mirrored in the frontmatter
	 * (a mirror would drift). Empty when the card or the folder is absent.
	 *
	 * @return list<array{name: string, size: ?int, mtime: ?int}>
	 */
	public function listAttachments(string $cardId): array {
		$adir = $this->attachmentsDir($cardId);
		if ($adir === null || !$this->storage->exists($adir) || !$this->storage->isDir($adir)) {
			return [];
		}
		$out = [];
		foreach ($this->storage->childFiles($adir) as $name) {
			$path = $adir . '/' . $name;
			$out[] = ['name' => $name, 'size' => $this->storage->size($path), 'mtime' => $this->storage->mtime($path)];
		}
		return $out;
	}

	/**
	 * Write one attachment into the card's attachments/ folder. The name is reduced
	 * to a basename (no path traversal); dotfiles and empty names are refused.
	 *
	 * @return bool True on success; false if the card is missing or the name is bad.
	 */
	public function saveAttachment(string $cardId, string $name, string $content): bool {
		$adir = $this->attachmentsDir($cardId);
		$name = basename($name);
		if ($adir === null || $name === '' || str_starts_with($name, '.')) {
			return false;
		}
		// write() creates the attachments/ folder if needed (both storages do).
		$this->storage->write($adir . '/' . $name, $content);
		return true;
	}

	/**
	 * Read one attachment's bytes for download, or null if it is absent.
	 */
	public function readAttachment(string $cardId, string $name): ?string {
		$adir = $this->attachmentsDir($cardId);
		$path = $adir === null ? null : $adir . '/' . basename($name);
		return ($path !== null && $this->storage->exists($path)) ? $this->storage->read($path) : null;
	}

	/**
	 * Remove one attachment by name.
	 *
	 * @return bool True if it was found and removed.
	 */
	public function deleteAttachment(string $cardId, string $name): bool {
		$adir = $this->attachmentsDir($cardId);
		$path = $adir === null ? null : $adir . '/' . basename($name);
		if ($path === null || !$this->storage->exists($path)) {
			return false;
		}
		$this->storage->delete($path);
		return true;
	}

	/**
	 * The card's attachments/ folder path, or null if the card is gone.
	 */
	private function attachmentsDir(string $cardId): ?string {
		$dir = $this->findCardDirAnywhere($cardId);
		return $dir === null ? null : $dir . '/attachments';
	}

	/**
	 * Replace the body of one comment, keeping its id, author and timestamp.
	 *
	 * @return bool True if the comment was found and rewritten.
	 */
	public function updateComment(string $cardId, string $commentId, string $body): bool {
		$file = $this->commentsFile($cardId);
		if ($file === null) {
			return false;
		}

		$found = false;
		$comments = array_map(
			static function (Comment $comment) use ($commentId, $body, &$found): Comment {
				if ($comment->id === $commentId) {
					$found = true;
					return new Comment($comment->id, $comment->author, $comment->created_at, $body);
				}
				return $comment;
			},
			Comment::parseAll($this->storage->read($file)),
		);

		if (!$found) {
			return false;
		}

		$this->writeComments($file, $comments);
		return true;
	}

	/**
	 * Remove one comment by id.
	 *
	 * @return bool True if the comment was found and removed.
	 */
	public function deleteComment(string $cardId, string $commentId): bool {
		$file = $this->commentsFile($cardId);
		if ($file === null) {
			return false;
		}

		$comments = Comment::parseAll($this->storage->read($file));
		$remaining = array_values(array_filter(
			$comments,
			static fn (Comment $comment): bool => $comment->id !== $commentId,
		));

		if (count($remaining) === count($comments)) {
			return false;
		}

		$this->writeComments($file, $remaining);
		return true;
	}

	/**
	 * Resolve a card's comments.md path, or null if the card or file is absent.
	 */
	private function commentsFile(string $cardId): ?string {
		$dir = $this->findCardDirAnywhere($cardId);
		if ($dir === null) {
			return null;
		}

		$file = $dir . '/comments.md';

		return $this->storage->exists($file) ? $file : null;
	}

	/**
	 * Rewrite comments.md from a list of comments (all with explicit ids).
	 *
	 * @param Comment[] $comments
	 */
	private function writeComments(string $file, array $comments): void {
		$blocks = array_map(static fn (Comment $comment): string => $comment->toMarkdown(), $comments);
		$this->storage->write($file, implode("\n", $blocks));
	}

	/**
	 * Serialize a card to its card.md content (frontmatter + body).
	 */
	private function serialize(Card $card): string {
		return $card->toYAMLFrontmatter() . "\n\n" . $card->description;
	}

	/**
	 * Relative path to a card's directory within a column, or null.
	 */
	private function findCardDir(string $column, string $cardId): ?string {
		$prefix = substr($cardId, 0, 8);
		foreach ($this->storage->childDirectories($column) as $cardFolder) {
			if (str_starts_with($cardFolder, $prefix)) {
				return $column . '/' . $cardFolder;
			}
		}

		return null;
	}

	/**
	 * Relative path to a card's directory across all columns, or null.
	 */
	private function findCardDirAnywhere(string $cardId): ?string {
		foreach ($this->storage->childDirectories('') as $columnFolder) {
			$dir = $this->findCardDir($columnFolder, $cardId);
			if ($dir !== null) {
				return $dir;
			}
		}

		return null;
	}
}
