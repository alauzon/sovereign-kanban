<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Controller;

use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST API for cards of a board.
 *
 * Thin Nextcloud boundary: resolves the board folder on disk, delegates to
 * the pure FileCardRepository, returns cards grouped by column as JSON.
 */
final class CardController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IConfig $config,
	) {
		parent::__construct('sovereign-kanban-md-persistence', $request);
	}

	/**
	 * List a board's cards, grouped by column.
	 *
	 * @param string $boardId Board slug (whitelisted to [a-z0-9-]).
	 * @return DataResponse JSON: {"cards": {"Backlog": [{id, title, ...}], ...}}
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $boardId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'not_logged_in'], 401);
		}

		// Whitelist the slug — also blocks path traversal (no '/', '..', '.').
		if (!preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'invalid_board_id'], 400);
		}

		$dataDir = (string) $this->config->getSystemValue('datadirectory', '/var/www/nextcloud/data');
		$boardDir = rtrim($dataDir, '/') . '/' . $user->getUID() . '/files/Kanban/' . $boardId;
		if (!is_dir($boardDir)) {
			return new DataResponse(['error' => 'board_not_found'], 404);
		}

		$repository = new FileCardRepository($boardDir);
		$cardsByColumn = [];
		foreach ($repository->listByColumn() as $column => $cards) {
			$cardsByColumn[$column] = array_map(
				static fn (Card $card): array => $card->toArray(),
				$cards,
			);
		}

		return new DataResponse(['cards' => $cardsByColumn]);
	}
}
