<?php
/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanImport\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\SovereignKanbanImport\Service\DeckImporter;

/**
 * API Controller for importing Deck cards to Sovereign Kanban.
 */
final class ImportController extends Controller {

	public function __construct(
		IRequest $request,
		private DeckImporter $importer,
		private IUserSession $userSession,
	) {
		parent::__construct('sovereign-kanban-import', $request);
	}

	/**
	 * Import the CURRENT user's own Deck boards into their Sovereign Kanban.
	 *
	 * Self-service and scoped to the caller: the target is always the session
	 * user, never a parameter. Importing for an arbitrary userId would let a
	 * caller write into someone else's files — the userId argument that used to
	 * exist here was exactly that footgun.
	 */
	#[NoAdminRequired]
	public function import(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'message' => 'Not logged in.'], 401);
		}

		try {
			$result = $this->importer->import($user->getUID());
			$skipped = $result['skipped'] ?? [];
			$errors = $result['errors'] ?? [];

			// Build a message that never reads a benign re-import as a failure.
			if ($result['boards'] === 0 && $skipped !== [] && $errors === []) {
				$message = sprintf(
					'Vos tableaux Deck sont déjà importés (%d) — rien de nouveau.',
					count($skipped),
				);
			} else {
				$parts = [];
				if ($result['boards'] > 0) {
					$parts[] = sprintf('%d tableau(x) importé(s) (%d carte(s))', $result['boards'], $result['cards']);
				}
				if ($skipped !== []) {
					$parts[] = sprintf('%d déjà présent(s), ignoré(s)', count($skipped));
				}
				if ($errors !== []) {
					$parts[] = sprintf('%d en erreur', count($errors));
				}
				$message = $parts === [] ? 'Aucun tableau Deck à importer.' : implode(' — ', $parts) . '.';
			}

			return new JSONResponse([
				'success' => true,
				'boards_imported' => $result['boards'],
				'cards_imported' => $result['cards'],
				'skipped' => $skipped,
				'errors' => $errors,
				'message' => $message,
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage(),
				'message' => 'Import impossible. Vérifiez que l\'app Deck est installée.',
			], 400);
		}
	}
}
