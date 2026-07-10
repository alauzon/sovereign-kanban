<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Controller;

use OCA\SovereignKanbanMdPersistence\Settings\AdminSettings;
use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NotBoardOwnerException;
use OCA\SovereignKanbanMdPersistence\Sharing\ShareNotOnBoardException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * REST API for board sharing.
 *
 * Thin boundary: delegates to BoardShareService (owner-only policy lives there)
 * and maps its exceptions to HTTP. Reads are CSRF-exempt (browser GET); writes
 * require the request token.
 */
final class ShareController extends Controller {

	private const SHAREE_LIMIT = 20;

	public function __construct(
		IRequest $request,
		private readonly BoardShareService $service,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct('sovereign-kanban-md-persistence', $request);
	}

	/**
	 * Suggest share recipients (users and groups) matching a search string.
	 *
	 * Applies the Kanban admin's suggestion mode (AdminSettings) — deliberately
	 * independent of the instance-wide Files sharing enumeration policy:
	 * - 'exact': only an exact uid/gid or display-name match comes back;
	 * - 'group': suggests among the requester's own groups and their members;
	 * - 'all': suggests across every account and group of the instance.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function sharees(string $search = ''): DataResponse {
		$search = mb_substr(trim($search), 0, 64);
		$current = $this->userSession->getUser();
		if ($search === '' || $current === null) {
			return new DataResponse(['sharees' => []]);
		}
		$mode = $this->appConfig->getValueString(
			'sovereign-kanban-md-persistence',
			AdminSettings::SUGGESTION_MODE_KEY,
			AdminSettings::SUGGESTION_MODE_DEFAULT,
		);

		$out = [];
		$add = static function (string $type, string $id, string $label) use (&$out): void {
			$out[$type . '|' . $id] = ['type' => $type, 'id' => $id, 'label' => $label];
		};

		if ($mode === 'all') {
			foreach ($this->userManager->searchDisplayName($search, self::SHAREE_LIMIT) as $user) {
				$add('user', $user->getUID(), $user->getDisplayName());
			}
			foreach ($this->groupManager->search($search, self::SHAREE_LIMIT) as $group) {
				$add('group', $group->getGID(), $group->getDisplayName());
			}
		} elseif ($mode === 'group') {
			$needle = mb_strtolower($search);
			foreach ($this->groupManager->getUserGroups($current) as $group) {
				if (str_contains(mb_strtolower($group->getDisplayName()), $needle)
					|| str_contains(mb_strtolower($group->getGID()), $needle)) {
					$add('group', $group->getGID(), $group->getDisplayName());
				}
				foreach ($group->getUsers() as $member) {
					if ($member->getUID() === $current->getUID()) {
						continue;
					}
					if (str_contains(mb_strtolower($member->getUID()), $needle)
						|| str_contains(mb_strtolower($member->getDisplayName()), $needle)) {
						$add('user', $member->getUID(), $member->getDisplayName());
					}
					if (count($out) >= self::SHAREE_LIMIT) {
						break 2;
					}
				}
			}
		} else {
			// 'exact' — and the fail-closed fallback for any unknown value.
			$user = $this->userManager->get($search);
			if ($user instanceof IUser) {
				$add('user', $user->getUID(), $user->getDisplayName());
			}
			foreach ($this->userManager->searchDisplayName($search, 5) as $candidate) {
				if ($candidate->getDisplayName() === $search) {
					$add('user', $candidate->getUID(), $candidate->getDisplayName());
				}
			}
			if ($this->groupManager->groupExists($search)) {
				$group = $this->groupManager->get($search);
				$add('group', $group->getGID(), $group->getDisplayName());
			}
		}

		return new DataResponse(['sharees' => array_slice(array_values($out), 0, self::SHAREE_LIMIT)]);
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
