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
	 * @param string $userId User ID to import for (optional)
	 * @return JSONResponse
	 */
	public function import(string $userId = ''): JSONResponse {
		try {
			$result = $this->importer->import($userId);

			$message = sprintf(
				'Successfully imported %d boards with %d total cards',
				$result['boards'],
				$result['cards'],
			);

			if (!empty($result['errors'])) {
				$message .= sprintf(' (%d errors)', count($result['errors']));
			}

			return new JSONResponse([
				'success' => true,
				'boards_imported' => $result['boards'],
				'cards_imported' => $result['cards'],
				'errors' => $result['errors'] ?? [],
				'message' => $message,
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage(),
				'message' => 'Import failed. Ensure Deck app is installed and you have admin rights.',
			], 400);
		}
	}
}
