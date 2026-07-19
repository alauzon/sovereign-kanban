<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Controller;

use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\Comment;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCA\SovereignKanbanMdPersistence\Service\MarkdownRenderer;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\ReceivedBoardLocator;
use OCA\SovereignKanbanMdPersistence\Sharing\SharePermissions;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * REST API for the cards of a board.
 *
 * Thin Nextcloud boundary: resolves the board folder on disk, delegates to
 * the pure FileCardRepository. Reads are CSRF-exempt; writes need the token.
 */
final class CardController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IRootFolder $rootFolder,
		private readonly MarkdownRenderer $markdown,
		private readonly ReceivedBoardLocator $receivedLocator,
		private readonly BoardShareService $shareService,
		private readonly IUserManager $userManager,
	) {
		parent::__construct('sovereign-kanban-md-persistence', $request);
	}

	/**
	 * List a board's cards, grouped by column.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $boardId): DataResponse {
		$repository = $this->repository($boardId);
		if ($repository === null) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		$cardsByColumn = [];
		foreach ($repository->listByColumn() as $column => $cards) {
			$cardsByColumn[$column] = array_map(
				fn (Card $card): array => $card->toArray() + [
					'excerpt_html' => $this->markdown->toHtml($card->description),
					'comment_count' => $repository->countComments($card->id),
				],
				$cards,
			);
		}

		return new DataResponse(['cards' => $cardsByColumn]);
	}

	/**
	 * Show a single card, including its description body.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $boardId, string $cardId): DataResponse {
		$repository = $this->repository($boardId);
		if ($repository === null || !$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		$card = $repository->findById($cardId);
		if ($card === null) {
			return new DataResponse(['error' => 'card_not_found'], 404);
		}

		// created_at and the file's mtime power the card's summary line. created_at
		// lives in the frontmatter; "modified" is the file's real last-write time.
		$detail = $this->detail($card);
		$detail['created_at'] = $card->created_at->format('Y-m-d\TH:i:s\Z');
		$mtime = $repository->mtimeOf($cardId);
		$detail['modified'] = $mtime !== null ? gmdate('Y-m-d\TH:i:s\Z', $mtime) : null;

		return new DataResponse(['card' => $detail]);
	}

	/**
	 * Create a card in a column (clean column name, mapped to its folder).
	 */
	#[NoAdminRequired]
	public function create(string $boardId, string $title, string $column, ?string $description = null, ?array $procedures = null): DataResponse {
		$repository = $this->writableRepositoryOrError($boardId);
		if ($repository instanceof DataResponse) {
			return $repository;
		}

		$title = trim($title);
		if ($title === '') {
			return new DataResponse(['error' => 'title_required'], 400);
		}

		$folder = $repository->resolveColumnFolder($column);
		if ($folder === null) {
			return new DataResponse(['error' => 'invalid_column'], 400);
		}

		// Whoever creates the card is its author, written once (Alain, 2026-07-19).
		$author = $this->userSession->getUser()?->getUID();
		$card = Card::create($title, $folder);
		// Optional initial body and suggested procedures — e.g. from a template.
		$hasBody = $description !== null && trim($description) !== '';
		$hasProcedures = $procedures !== null && $procedures !== [];
		$card = new Card(
			id: $card->id,
			title: $card->title,
			column: $card->column,
			description: $hasBody ? $description : '',
			created_at: $card->created_at,
			assignees: $card->assignees,
			due_date: $card->due_date,
			procedures: $hasProcedures ? array_values($procedures) : [],
			author: $author,
		);
		$repository->save($card);
		$repository->appendActivity($card->id, 'created', $author, ['title' => $card->title]);

		return new DataResponse(['card' => $this->detail($card)], 201);
	}

	/**
	 * Update a card's title and/or description body.
	 */
	#[NoAdminRequired]
	public function update(
		string $boardId,
		string $cardId,
		?string $title = null,
		?string $description = null,
		?string $due_date = null,
		?array $assignees = null,
		?string $priority = null,
		?array $tags = null,
		?string $phase = null,
		?string $start_date = null,
		?string $completed_at = null,
	): DataResponse {
		if (!$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}
		$repository = $this->writableRepositoryOrError($boardId);
		if ($repository instanceof DataResponse) {
			return $repository;
		}

		$card = $repository->findById($cardId);
		if ($card === null) {
			return new DataResponse(['error' => 'card_not_found'], 404);
		}

		// Reject a malformed date before it reaches the file. normalizeDate is
		// tolerant (it must read back already-corrupt cards); the strict guard
		// belongs here, on the write path. Found 2026-07-18: Chrome accepted a
		// 6-digit year and the tolerant normalize stored '202607-07-19' verbatim.
		if (($due_date !== null && !Card::isValidDateInput($due_date))
			|| ($start_date !== null && !Card::isValidDateInput($start_date))) {
			return new DataResponse(['error' => 'invalid_date'], 400);
		}

		// due_date: null = leave unchanged, '' = clear, else set.
		// Normalized by Card::normalizeDate and NOWHERE else. This line used to
		// substr($due_date, 0, 10) on its own, which silently dropped the time
		// the browser sent — a bug that survived a green conformance suite
		// because the suite could not reach this layer. Duplicated normalization
		// is how a fix lands in one place and not the other.
		$newDue = $card->due_date;
		if ($due_date !== null) {
			$newDue = Card::normalizeDate($due_date);
		}

		// start_date: null = leave unchanged, '' = clear, else set.
		$newStart = $card->start_date;
		if ($start_date !== null) {
			$newStart = Card::normalizeDate($start_date);
		}

		// assignees: null = leave unchanged, else replace (trimmed, non-empty).
		// Every entry must be an EXISTING Nextcloud account (D5 in the Deck→SK
		// correspondence: the free-text field used to write typos and display
		// names into card.md as if they were account ids). All-or-nothing: one
		// bad entry rejects the list, no partial write. Validation fires on the
		// parameter only — a file already carrying stale garbage still loads
		// and still accepts updates that do not touch assignees.
		$newAssignees = $card->assignees;
		if ($assignees !== null) {
			$newAssignees = array_values(array_filter(array_map('trim', $assignees), static fn ($a) => $a !== ''));
			$invalid = array_values(array_filter(
				$newAssignees,
				fn (string $uid): bool => $this->userManager->get($uid) === null,
			));
			if ($invalid !== []) {
				return new DataResponse(['error' => 'invalid_assignee', 'invalid' => $invalid], 400);
			}
		}

		// priority: null = leave, '' = clear, else set.
		$newPriority = $card->priority;
		if ($priority !== null) {
			$newPriority = ($priority === '') ? null : $priority;
		}

		// tags: null = leave, else replace (trimmed, non-empty).
		$newTags = $card->tags;
		if ($tags !== null) {
			$newTags = array_values(array_filter(array_map('trim', $tags), static fn ($t) => $t !== ''));
		}

		// phase: null = leave, '' = clear, else set (1-4 expected).
		$newPhase = $card->phase;
		if ($phase !== null) {
			$newPhase = ($phase === '') ? null : (int) $phase;
		}

		// completed_at: null = leave, '' = reopen (clear), else set the done instant.
		$newCompleted = $card->completed_at;
		if ($completed_at !== null) {
			$newCompleted = ($completed_at === '') ? null : $completed_at;
		}

		$newTitle = ($title !== null && trim($title) !== '') ? trim($title) : $card->title;

		$updated = new Card(
			id: $card->id,
			title: $newTitle,
			column: $card->column,
			description: $description ?? $card->description,
			created_at: $card->created_at,
			assignees: $newAssignees,
			due_date: $newDue,
			procedures: $card->procedures,
			priority: $newPriority,
			tags: $newTags,
			phase: $newPhase,
			start_date: $newStart,
			completed_at: $newCompleted,
			// author is written once at creation and never rewritten by an edit —
			// but it MUST be carried through here, or every update erases it. This
			// is the exact field-by-field drop the extra[] comment below warns of.
			author: $card->author,
			// Whatever the user put in their own file and we do not understand.
			// Rebuilding a Card field by field is exactly how it got dropped:
			// every edit from the browser used to delete the frontmatter keys
			// the user had added, while the format spec claimed rule 5 held.
			// If you add a field to Card, add it here too.
			extra: $card->extra,
		);
		$repository->update($updated);

		// Sovereign activity journal (option C): record what changed. done/reopened
		// are their own verbs; every other field edit folds into one 'updated' event
		// carrying the English field ids (the UI translates them for display).
		$actor = $this->userSession->getUser()?->getUID();
		if ($newCompleted !== $card->completed_at) {
			$repository->appendActivity($card->id, $newCompleted === null ? 'reopened' : 'done', $actor);
		}
		$changed = [];
		if ($newTitle !== $card->title) {
			$changed[] = 'title';
		}
		if ($description !== null && $description !== $card->description) {
			$changed[] = 'description';
		}
		if ($newDue !== $card->due_date) {
			$changed[] = 'due_date';
		}
		if ($newStart !== $card->start_date) {
			$changed[] = 'start_date';
		}
		if ($newAssignees !== $card->assignees) {
			$changed[] = 'assignees';
		}
		if ($newPriority !== $card->priority) {
			$changed[] = 'priority';
		}
		if ($newTags !== $card->tags) {
			$changed[] = 'tags';
		}
		if ($newPhase !== $card->phase) {
			$changed[] = 'phase';
		}
		if ($changed !== []) {
			$repository->appendActivity($card->id, 'updated', $actor, ['fields' => $changed]);
		}

		return new DataResponse(['card' => $this->detail($updated)]);
	}

	/**
	 * Delete a card.
	 */
	#[NoAdminRequired]
	public function destroy(string $boardId, string $cardId): DataResponse {
		if (!$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}
		$repository = $this->writableRepositoryOrError($boardId);
		if ($repository instanceof DataResponse) {
			return $repository;
		}

		$repository->deleteById($cardId);

		return new DataResponse(['deleted' => true]);
	}

	/**
	 * Move a card to another column (drag-drop). toColumn is a clean name.
	 */
	#[NoAdminRequired]
	public function move(string $boardId, string $cardId, string $toColumn): DataResponse {
		if (!$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}
		$repository = $this->writableRepositoryOrError($boardId);
		if ($repository instanceof DataResponse) {
			return $repository;
		}

		$card = $repository->findById($cardId);
		if ($card === null) {
			return new DataResponse(['error' => 'card_not_found'], 404);
		}

		$targetFolder = $repository->resolveColumnFolder($toColumn);
		if ($targetFolder === null) {
			return new DataResponse(['error' => 'invalid_column'], 400);
		}

		if ($targetFolder !== $card->column) {
			$repository->moveCard($cardId, $card->column, $targetFolder);
		}

		return new DataResponse(['card' => $this->detail($repository->findById($cardId))]);
	}

	/**
	 * List a card's comments.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function comments(string $boardId, string $cardId): DataResponse {
		$repository = $this->repository($boardId);
		if ($repository === null || !$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		$comments = array_map(
			fn (Comment $comment): array => $comment->toArray() + ['body_html' => $this->markdown->toHtml($comment->body)],
			$repository->listComments($cardId),
		);

		return new DataResponse(['comments' => $comments]);
	}

	/**
	 * Add a comment to a card, authored by the current user.
	 */
	#[NoAdminRequired]
	public function addComment(string $boardId, string $cardId, string $body): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}
		$repository = $this->writableRepositoryOrError($boardId);
		if ($repository instanceof DataResponse) {
			return $repository;
		}

		$body = trim($body);
		if ($body === '') {
			return new DataResponse(['error' => 'body_required'], 400);
		}
		if ($repository->findById($cardId) === null) {
			return new DataResponse(['error' => 'card_not_found'], 404);
		}

		$author = $user->getDisplayName() ?: $user->getUID();
		$comment = Comment::create($author, $body);
		$repository->addComment($cardId, $comment);
		$repository->appendActivity($cardId, 'commented', $user->getUID());

		return new DataResponse(['comment' => $comment->toArray()], 201);
	}

	/**
	 * Edit the body of an existing comment (Markdown).
	 */
	#[NoAdminRequired]
	public function updateComment(string $boardId, string $cardId, string $commentId, string $body): DataResponse {
		if (!$this->validCardId($cardId) || !$this->validCommentId($commentId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}
		$repository = $this->writableRepositoryOrError($boardId);
		if ($repository instanceof DataResponse) {
			return $repository;
		}

		$body = trim($body);
		if ($body === '') {
			return new DataResponse(['error' => 'body_required'], 400);
		}
		if (!$repository->updateComment($cardId, $commentId, $body)) {
			return new DataResponse(['error' => 'comment_not_found'], 404);
		}

		return new DataResponse(['updated' => true]);
	}

	/**
	 * Delete a comment by id.
	 */
	#[NoAdminRequired]
	public function destroyComment(string $boardId, string $cardId, string $commentId): DataResponse {
		if (!$this->validCardId($cardId) || !$this->validCommentId($commentId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}
		$repository = $this->writableRepositoryOrError($boardId);
		if ($repository instanceof DataResponse) {
			return $repository;
		}

		if (!$repository->deleteComment($cardId, $commentId)) {
			return new DataResponse(['error' => 'comment_not_found'], 404);
		}

		return new DataResponse(['deleted' => true]);
	}

	/**
	 * The card's sovereign activity journal, chronological (oldest first).
	 *
	 * Each event's actor uid is resolved to a display label for the UI; the raw
	 * uid stays in 'actor' so the record remains identifier-stable.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function activity(string $boardId, string $cardId): DataResponse {
		$repository = $this->repository($boardId);
		if ($repository === null || !$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		$events = array_map(
			fn (array $event): array => $event + ['actor_label' => $this->displayName($event['actor'] ?? null)],
			$repository->listActivity($cardId),
		);

		return new DataResponse(['activity' => $events]);
	}

	/**
	 * Full card shape for the detail view (includes the description body).
	 *
	 * @return array{id: string, title: string, column: string, description: string, due_date: ?string, start_date: ?string, assignees: list<string>, procedures: list<string>, priority: ?string, tags: list<string>, phase: ?int}
	 */
	private function detail(Card $card): array {
		return [
			'id' => $card->id,
			'title' => $card->title,
			'column' => $card->column,
			'description' => $card->description,
			'due_date' => $card->due_date,
			'start_date' => $card->start_date,
			'assignees' => array_values($card->assignees),
			'procedures' => array_values($card->procedures),
			'priority' => $card->priority,
			'tags' => array_values($card->tags),
			'phase' => $card->phase,
			'completed_at' => $card->completed_at,
			'author' => $card->author,
			'author_label' => $this->displayName($card->author),
			'checklist' => $card->checklist(),
		];
	}

	/**
	 * Resolve a uid to its Nextcloud display name for UI labels, falling back to
	 * the uid when the account is unknown (never invents a name). Null in, null out.
	 */
	private function displayName(?string $uid): ?string {
		if ($uid === null || $uid === '') {
			return null;
		}
		return $this->userManager->get($uid)?->getDisplayName() ?? $uid;
	}

	/**
	 * A card id is a UUID — whitelist blocks path traversal.
	 */
	private function validCardId(string $cardId): bool {
		return (bool) preg_match('/^[0-9a-fA-F-]+$/', $cardId);
	}

	/**
	 * A comment id is a UUID or a derived hex token — whitelist blocks traversal.
	 */
	private function validCommentId(string $commentId): bool {
		return (bool) preg_match('/^[0-9a-fA-F-]+$/', $commentId);
	}

	/**
	 * Build a FileCardRepository rooted at a board folder, or null if the
	 * user is absent, the board id is invalid, or the board does not exist.
	 */
	private function repository(string $boardId): ?FileCardRepository {
		$user = $this->userSession->getUser();
		if ($user === null || !preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return null;
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$path = 'Kanban/' . $boardId;
		// Own board under Kanban/, else a board shared TO this user (Option B,
		// §12): a received share sits at the Files root, resolved by id.
		$boardFolder = $userFolder->nodeExists($path)
			? $userFolder->get($path)
			: $this->receivedLocator->folderFor($boardId);
		if (!$boardFolder instanceof Folder) {
			return null;
		}

		return new FileCardRepository(new NextcloudStorage($boardFolder));
	}

	/**
	 * A card repository the current user may WRITE to, or the error response to
	 * return as-is (400 unavailable / 403 read_only).
	 *
	 * Own boards (under Kanban/) are always writable. A board shared TO the user
	 * is writable only if the share grants UPDATE. The read-only bypass fix
	 * (Steve, 2026-07-12): the received folder node resolves in the owner's
	 * scope and so reports the owner's full permissions — writing through it
	 * would silently bypass a read-only share. Authorization therefore comes
	 * from the share's granted permission (BoardShareService), never the node.
	 */
	private function writableRepositoryOrError(string $boardId): FileCardRepository|DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null || !preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$path = 'Kanban/' . $boardId;
		if ($userFolder->nodeExists($path)) {
			$boardFolder = $userFolder->get($path);

			return $boardFolder instanceof Folder
				? new FileCardRepository(new NextcloudStorage($boardFolder))
				: new DataResponse(['error' => 'unavailable'], 400);
		}

		// Board shared TO this user: gate the write on the share's granted
		// permission, unioned across all channels that reach the user.
		$permission = $this->shareService->receivedPermission($boardId);
		if ($permission === null) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}
		if (!SharePermissions::allowsWrite($permission)) {
			return new DataResponse(['error' => 'read_only'], 403);
		}

		$boardFolder = $this->receivedLocator->folderFor($boardId);
		if (!$boardFolder instanceof Folder) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		return new FileCardRepository(new NextcloudStorage($boardFolder));
	}
}
