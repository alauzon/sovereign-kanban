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
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IShare;

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
		private readonly ISearch $collaboratorSearch,
	) {
		parent::__construct('sovereign-kanban-md-persistence', $request);
	}

	/**
	 * Suggest share recipients matching a search string, for ONE share type —
	 * the type selector drives what gets suggested (people never show up when
	 * the user is picking a team).
	 *
	 * Applies the Kanban admin's per-type policy (AdminSettings) — deliberately
	 * independent of the instance-wide Files enumeration policy. Instance
	 * admins always get the widest mode. Exact ids keep working in every mode
	 * (the share POST does not depend on suggestions).
	 *
	 * @param string $search Substring typed by the user.
	 * @param string $type 'user' | 'group' | 'team'.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function sharees(string $search = '', string $type = 'user'): DataResponse {
		$search = mb_substr(trim($search), 0, 64);
		$current = $this->userSession->getUser();
		if ($search === '' || $current === null) {
			return new DataResponse(['sharees' => []]);
		}
		$isAdmin = $this->groupManager->isAdmin($current->getUID());
		$needle = mb_strtolower($search);

		$out = [];
		$add = static function (string $type, string $id, string $label) use (&$out): void {
			$out[$type . '|' . $id] = ['type' => $type, 'id' => $id, 'label' => $label];
		};

		if ($type === 'group') {
			$mode = $isAdmin ? 'all' : $this->appConfig->getValueString(
				'sovereign-kanban-md-persistence',
				AdminSettings::GROUP_MODE_KEY,
				AdminSettings::SCOPE_MODE_DEFAULT,
			);
			if ($mode === 'all') {
				foreach ($this->groupManager->search($search, self::SHAREE_LIMIT) as $group) {
					$add('group', $group->getGID(), $group->getDisplayName());
				}
			} else {
				foreach ($this->groupManager->getUserGroups($current) as $group) {
					if (str_contains(mb_strtolower($group->getDisplayName()), $needle)
						|| str_contains(mb_strtolower($group->getGID()), $needle)) {
						$add('group', $group->getGID(), $group->getDisplayName());
					}
				}
			}
		} elseif ($type === 'team') {
			// Teams (Circles) expose no global enumeration API: both modes list
			// the teams visible to the requester via the collaborator plugin.
			try {
				[$result] = $this->collaboratorSearch->search($search, [IShare::TYPE_CIRCLE], false, self::SHAREE_LIMIT, 0);
				foreach (array_merge($result['exact']['circles'] ?? [], $result['circles'] ?? []) as $entry) {
					$id = (string) ($entry['value']['shareWith'] ?? '');
					if ($id !== '') {
						$add('team', $id, (string) ($entry['label'] ?? $id));
					}
				}
			} catch (\Throwable) {
				// Teams app absent — no suggestions, exact input still works.
			}
		} else {
			$mode = $isAdmin ? 'all' : $this->appConfig->getValueString(
				'sovereign-kanban-md-persistence',
				AdminSettings::SUGGESTION_MODE_KEY,
				AdminSettings::SUGGESTION_MODE_DEFAULT,
			);
			if ($mode === 'all') {
				foreach ($this->userManager->searchDisplayName($search, self::SHAREE_LIMIT) as $user) {
					$add('user', $user->getUID(), $user->getDisplayName());
				}
			} elseif ($mode === 'group') {
				foreach ($this->groupManager->getUserGroups($current) as $group) {
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
