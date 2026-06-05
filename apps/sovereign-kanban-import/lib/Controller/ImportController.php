<?php
/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanImport\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\SovereignKanbanImport\Service\DeckImporter;

/**
 * API Controller for importing Deck cards to Sovereign Kanban.
 */
final class ImportController extends Controller {

	public function __construct(
		IRequest $request,
		private DeckImporter $importer,
	) {
		parent::__construct('sovereign-kanban-import', $request);
	}

	/**
	 * Import all Deck boards and cards to Sovereign Kanban.
	 *
	 * @NoAdminRequired
	 * @return JSONResponse
	 */
	public function import(): JSONResponse {
		try {
			$result = $this->importer->import();

			return new JSONResponse([
				'success' => true,
				'imported' => $result,
				'message' => sprintf(
					'Successfully imported %d boards with %d total cards',
					$result['boards'],
					$result['cards'],
				),
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], 400);
		}
	}
}
