<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Controller;

use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NotBoardOwnerException;
use OCA\SovereignKanbanMdPersistence\Sharing\ShareNotOnBoardException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * REST API for board sharing.
 *
 * Thin boundary: delegates to BoardShareService (owner-only policy lives there)
 * and maps its exceptions to HTTP. Reads are CSRF-exempt (browser GET); writes
 * require the request token.
 */
final class ShareController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly BoardShareService $service,
	) {
		parent::__construct('sovereign-kanban-md-persistence', $request);
	}

	/**
	 * List a board's shares.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $boardId): DataResponse {
		if (!preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'invalid_board_id'], 400);
		}
		try {
			return new DataResponse(['shares' => $this->service->listShares($boardId)]);
		} catch (NotBoardOwnerException) {
			return new DataResponse(['error' => 'not_owner'], 403);
		}
	}

	/**
	 * Share a board with a recipient.
	 *
	 * @param string $shareType 'user' | 'group' | 'team'.
	 * @param string $shareWith Recipient id (uid, gid, team id).
	 * @param string $level 'read' | 'collaborate'.
	 */
	#[NoAdminRequired]
	public function create(string $boardId, string $shareType, string $shareWith, string $level = 'read'): DataResponse {
		if (!preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'invalid_board_id'], 400);
		}
		try {
			$id = $this->service->share($boardId, $shareType, $shareWith, $level);

			return new DataResponse(['id' => $id], 201);
		} catch (NotBoardOwnerException) {
			return new DataResponse(['error' => 'not_owner'], 403);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => 'invalid_request', 'message' => $e->getMessage()], 400);
		}
	}

	/**
	 * Revoke a share from a board.
	 */
	#[NoAdminRequired]
	public function destroy(string $boardId, string $shareId): DataResponse {
		if (!preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['error' => 'invalid_board_id'], 400);
		}
		try {
			$this->service->revoke($boardId, $shareId);

			return new DataResponse(['revoked' => true]);
		} catch (NotBoardOwnerException) {
			return new DataResponse(['error' => 'not_owner'], 403);
		} catch (ShareNotOnBoardException) {
			return new DataResponse(['error' => 'share_not_found'], 404);
		}
	}
}
