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
 * disk, delegates to the pure FileBoardRepository, returns JSON.
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
	 *
	 * @return DataResponse JSON: {"boards": [{id, name, color, columns}, ...]}
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'not_logged_in'], 401);
		}

		$dataDir = (string) $this->config->getSystemValue('datadirectory', '/var/www/nextcloud/data');
		$kanbanRoot = rtrim($dataDir, '/') . '/' . $user->getUID() . '/files/Kanban';

		$repository = new FileBoardRepository($kanbanRoot);
		$boards = array_map(
			static fn (Board $board): array => $board->toArray(),
			$repository->list(),
		);

		return new DataResponse(['boards' => $boards]);
	}
}
