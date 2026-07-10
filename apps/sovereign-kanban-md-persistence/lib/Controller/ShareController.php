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
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IRequest;
use OCP\Share\IShare;

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
		private readonly ISearch $collaboratorSearch,
	) {
		parent::__construct('sovereign-kanban-md-persistence', $request);
	}

	/** OCP share-type constants → our share-type names (inverse of the gateway map). */
	private const NC_TO_TYPE = [
		IShare::TYPE_USER => 'user',
		IShare::TYPE_GROUP => 'group',
		IShare::TYPE_CIRCLE => 'team',
	];

	/**
	 * Suggest share recipients (users, groups, teams) matching a search string.
	 *
	 * Backs the share-field autocomplete. Delegates to the collaborator search
	 * used by Files sharing, so visibility restrictions (e.g. share
	 * autocomplete settings) are enforced by Nextcloud, not by us.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function sharees(string $search = ''): DataResponse {
		$search = mb_substr(trim($search), 0, 64);
		if ($search === '') {
			return new DataResponse(['sharees' => []]);
		}

		$types = array_keys(self::NC_TO_TYPE);
		try {
			[$result] = $this->collaboratorSearch->search($search, $types, false, 20, 0);
		} catch (\Throwable) {
			// A missing collaborator plugin (e.g. Teams app absent) must not
			// break the suggestions — retry with the two core types.
			[$result] = $this->collaboratorSearch->search(
				$search, [IShare::TYPE_USER, IShare::TYPE_GROUP], false, 20, 0,
			);
		}

		$out = [];
		$seen = [];
		foreach (['users', 'groups', 'circles'] as $bucket) {
			$entries = array_merge($result['exact'][$bucket] ?? [], $result[$bucket] ?? []);
			foreach ($entries as $entry) {
				$ncType = (int) ($entry['value']['shareType'] ?? -1);
				$id = (string) ($entry['value']['shareWith'] ?? '');
				if ($id === '' || !isset(self::NC_TO_TYPE[$ncType])) {
					continue;
				}
				$key = $ncType . '|' . $id;
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$out[] = [
					'type' => self::NC_TO_TYPE[$ncType],
					'id' => $id,
					'label' => (string) ($entry['label'] ?? $id),
				];
			}
		}

		return new DataResponse(['sharees' => array_slice($out, 0, 20)]);
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
