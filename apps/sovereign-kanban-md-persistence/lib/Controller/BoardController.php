<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Controller;

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
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
		private readonly IConfig $config,
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
			static fn (Board $board): array => $board->toArray(),
			$repository->list(),
		);

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
		$repository->create($board);

		return new DataResponse(['board' => $board->toArray()], 201);
	}

	/**
	 * Update a board's name and/or color. The id (slug) stays stable.
	 *
	 * @param string $boardId Board slug (whitelisted to [a-z0-9-]).
	 */
	#[NoAdminRequired]
	public function update(string $boardId, ?string $name = null, ?string $color = null): DataResponse {
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
		$repository->save($board);

		return new DataResponse(['board' => $board->toArray()]);
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
	 * Build a FileBoardRepository rooted at the current user's Kanban
	 * folder, or null if no user is logged in.
	 */
	private function repository(): ?FileBoardRepository {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$dataDir = (string) $this->config->getSystemValue('datadirectory', '/var/www/nextcloud/data');
		$kanbanRoot = rtrim($dataDir, '/') . '/' . $user->getUID() . '/files/Kanban';

		return new FileBoardRepository($kanbanRoot);
	}
}
