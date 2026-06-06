<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Controller;

use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\Comment;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
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
				static fn (Card $card): array => $card->toArray(),
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

		return new DataResponse(['card' => $this->detail($card)]);
	}

	/**
	 * Create a card in a column (clean column name, mapped to its folder).
	 */
	#[NoAdminRequired]
	public function create(string $boardId, string $title, string $column): DataResponse {
		$repository = $this->repository($boardId);
		if ($repository === null) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		$title = trim($title);
		if ($title === '') {
			return new DataResponse(['error' => 'title_required'], 400);
		}

		$folder = $repository->resolveColumnFolder($column);
		if ($folder === null) {
			return new DataResponse(['error' => 'invalid_column'], 400);
		}

		$card = Card::create($title, $folder);
		$repository->save($card);

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
	): DataResponse {
		$repository = $this->repository($boardId);
		if ($repository === null || !$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		$card = $repository->findById($cardId);
		if ($card === null) {
			return new DataResponse(['error' => 'card_not_found'], 404);
		}

		// due_date: null = leave unchanged, '' = clear, else set.
		$newDue = $card->due_date;
		if ($due_date !== null) {
			$newDue = ($due_date === '') ? null : substr($due_date, 0, 10);
		}

		// assignees: null = leave unchanged, else replace (trimmed, non-empty).
		$newAssignees = $card->assignees;
		if ($assignees !== null) {
			$newAssignees = array_values(array_filter(array_map('trim', $assignees), static fn ($a) => $a !== ''));
		}

		$updated = new Card(
			id: $card->id,
			title: ($title !== null && trim($title) !== '') ? trim($title) : $card->title,
			column: $card->column,
			description: $description ?? $card->description,
			created_at: $card->created_at,
			assignees: $newAssignees,
			due_date: $newDue,
		);
		$repository->update($updated);

		return new DataResponse(['card' => $this->detail($updated)]);
	}

	/**
	 * Delete a card.
	 */
	#[NoAdminRequired]
	public function destroy(string $boardId, string $cardId): DataResponse {
		$repository = $this->repository($boardId);
		if ($repository === null || !$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		$repository->deleteById($cardId);

		return new DataResponse(['deleted' => true]);
	}

	/**
	 * Move a card to another column (drag-drop). toColumn is a clean name.
	 */
	#[NoAdminRequired]
	public function move(string $boardId, string $cardId, string $toColumn): DataResponse {
		$repository = $this->repository($boardId);
		if ($repository === null || !$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
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
			static fn (Comment $comment): array => $comment->toArray(),
			$repository->listComments($cardId),
		);

		return new DataResponse(['comments' => $comments]);
	}

	/**
	 * Add a comment to a card, authored by the current user.
	 */
	#[NoAdminRequired]
	public function addComment(string $boardId, string $cardId, string $body): DataResponse {
		$repository = $this->repository($boardId);
		$user = $this->userSession->getUser();
		if ($repository === null || $user === null || !$this->validCardId($cardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
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

		return new DataResponse(['comment' => $comment->toArray()], 201);
	}

	/**
	 * Full card shape for the detail view (includes the description body).
	 *
	 * @return array{id: string, title: string, column: string, description: string, assignees: list<string>}
	 */
	private function detail(Card $card): array {
		return [
			'id' => $card->id,
			'title' => $card->title,
			'column' => $card->column,
			'description' => $card->description,
			'due_date' => $card->due_date,
			'assignees' => array_values($card->assignees),
		];
	}

	/**
	 * A card id is a UUID — whitelist blocks path traversal.
	 */
	private function validCardId(string $cardId): bool {
		return (bool) preg_match('/^[0-9a-fA-F-]+$/', $cardId);
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
		if (!$userFolder->nodeExists($path)) {
			return null;
		}
		$boardFolder = $userFolder->get($path);
		if (!$boardFolder instanceof Folder) {
			return null;
		}

		return new FileCardRepository(new NextcloudStorage($boardFolder));
	}
}
