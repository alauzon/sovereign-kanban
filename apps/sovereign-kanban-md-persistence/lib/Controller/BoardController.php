<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Controller;

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
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
 * REST API for boards.
 *
 * Thin Nextcloud boundary: resolves the current user's Kanban folder on
 * disk, delegates to the pure FileBoardRepository, returns JSON. Reads are
 * CSRF-exempt (browser GET); writes require the request token.
 */
final class BoardController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IRootFolder $rootFolder,
		private readonly BoardShareService $shareService,
	) {
		parent::__construct('sovereign-kanban-md-persistence', $request);
	}

	/**
	 * List the current user's boards.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): DataResponse {
		$repository = $this->repository();
		if ($repository === null) {
			return new DataResponse(['error' => 'not_logged_in'], 401);
		}

		$boards = array_map(
			static fn (Board $board): array => $board->toArray() + ['shared' => false, 'owner' => null],
			$repository->list(),
		);
		// Boards shared TO this user (Option B, spec §12), marked `shared` + `owner`.
		// Columns/cards of a received board aren't loaded here yet — it appears in
		// the list; full navigation needs storage rooted at the share path (next sub-lot).
		foreach ($this->shareService->receivedBoards() as $received) {
			$boards[] = [
				'id' => $received['id'],
				'name' => $received['name'],
				'color' => '#0082c9',
				'columns' => [],
				'tags' => [],
				'shared' => true,
				'owner' => $received['owner'],
			];
		}

		return new DataResponse(['boards' => $boards]);
	}

	/**
	 * Create a board from a name + color.
	 *
	 * @param string $name Human name (slugified into a stable id).
	 * @param string $color Hex color for the board.
	 */
	#[NoAdminRequired]
	public function create(string $name, string $color = '#0082c9'): DataResponse {
		$repository = $this->repository();
		if ($repository === null) {
			return new DataResponse(['error' => 'not_logged_in'], 401);
		}

		$name = trim($name);
		if ($name === '') {
			return new DataResponse(['error' => 'name_required'], 400);
		}

		$board = Board::create($name, $color);
		if ($repository->find($board->id) !== null) {
			// The name slugified onto an existing board's folder. Refuse rather
			// than overwrite its .board.yml (which would reset columns to
			// defaults and orphan its cards).
			return new DataResponse(['error' => 'board_exists', 'id' => $board->id], 409);
		}
		$repository->create($board);

		return new DataResponse(['board' => $board->toArray()], 201);
	}

	/**
	 * Update a board's name, color, and/or tag palette. The id (slug) stays stable.
	 *
	 * @param string $boardId Board slug (whitelisted to [a-z0-9-]).
	 * @param ?string $name New display name, or null to leave unchanged.
	 * @param ?string $color New board color, or null to leave unchanged.
	 * @param ?array $tags Full replacement tag palette (list of {name, color}),
	 *   or null to leave the palette unchanged.
	 */
	#[NoAdminRequired]
	public function update(string $boardId, ?string $name = null, ?string $color = null, ?array $tags = null): DataResponse {
		$repository = $this->repository();
		if ($repository === null) {
			return new DataResponse(['error' => 'not_logged_in'], 401);
		}
		if (!preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'invalid_board_id'], 400);
		}

		$board = $repository->find($boardId);
		if ($board === null) {
			return new DataResponse(['error' => 'board_not_found'], 404);
		}

		if ($name !== null && trim($name) !== '') {
			$board = $board->withName(trim($name));
		}
		if ($color !== null && $color !== '') {
			$board = $board->withColor($color);
		}
		if ($tags !== null) {
			$board = $board->withTags($this->sanitizePalette($tags));
		}
		$repository->save($board);

		return new DataResponse(['board' => $board->toArray()]);
	}

	/**
	 * Normalize an incoming tag palette: keep entries with a non-empty name and
	 * a valid hex color, deduplicate by name (last wins), drop the rest.
	 *
	 * @param array $tags Raw palette from the request.
	 *
	 * @return list<array{name: string, color: string}> Clean palette.
	 */
	private function sanitizePalette(array $tags): array {
		$clean = [];
		foreach ($tags as $tag) {
			if (!is_array($tag)) {
				continue;
			}
			$name = trim((string) ($tag['name'] ?? ''));
			$color = trim((string) ($tag['color'] ?? ''));
			if ($name === '' || !preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color)) {
				continue;
			}
			$clean[$name] = ['name' => $name, 'color' => $color];
		}

		return array_values($clean);
	}

	/**
	 * Delete a board and its entire folder.
	 */
	#[NoAdminRequired]
	public function destroy(string $boardId): DataResponse {
		$repository = $this->repository();
		if ($repository === null) {
			return new DataResponse(['error' => 'not_logged_in'], 401);
		}
		if (!preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'invalid_board_id'], 400);
		}

		$repository->delete($boardId);

		return new DataResponse(['deleted' => true]);
	}

	/**
	 * Add a column to a board.
	 */
	#[NoAdminRequired]
	public function addColumn(string $boardId, string $name): DataResponse {
		$repository = $this->repository();
		if ($repository === null || !preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}
		$name = trim($name);
		if ($name === '') {
			return new DataResponse(['error' => 'name_required'], 400);
		}

		$board = $repository->addColumn($boardId, $name);

		return $board === null
			? new DataResponse(['error' => 'board_not_found'], 404)
			: new DataResponse(['board' => $board->toArray()], 201);
	}

	/**
	 * Rename a column.
	 */
	#[NoAdminRequired]
	public function renameColumn(string $boardId, string $from, string $to): DataResponse {
		$repository = $this->repository();
		if ($repository === null || !preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}
		$to = trim($to);
		if ($to === '') {
			return new DataResponse(['error' => 'name_required'], 400);
		}

		$board = $repository->renameColumn($boardId, $from, $to);

		return $board === null
			? new DataResponse(['error' => 'board_not_found'], 404)
			: new DataResponse(['board' => $board->toArray()]);
	}

	/**
	 * Remove a column (and its cards).
	 */
	#[NoAdminRequired]
	public function removeColumn(string $boardId, string $name): DataResponse {
		$repository = $this->repository();
		if ($repository === null || !preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		$board = $repository->removeColumn($boardId, $name);

		return $board === null
			? new DataResponse(['error' => 'board_not_found'], 404)
			: new DataResponse(['board' => $board->toArray()]);
	}

	/**
	 * Reorder a board's columns.
	 *
	 * @param string[] $columns The full ordered list of column names.
	 */
	#[NoAdminRequired]
	public function reorderColumns(string $boardId, array $columns): DataResponse {
		$repository = $this->repository();
		if ($repository === null || !preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		$board = $repository->reorderColumns($boardId, $columns);

		return $board === null
			? new DataResponse(['error' => 'board_not_found'], 404)
			: new DataResponse(['board' => $board->toArray()]);
	}

	/**
	 * Build a FileBoardRepository rooted at the current user's Kanban
	 * folder, or null if no user is logged in.
	 */
	private function repository(): ?FileBoardRepository {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$kanban = $userFolder->nodeExists('Kanban')
			? $userFolder->get('Kanban')
			: $userFolder->newFolder('Kanban');
		if (!$kanban instanceof Folder) {
			return null;
		}

		return new FileBoardRepository(new NextcloudStorage($kanban));
	}
}
