<?php

/**
 * @file
 * Nextcloud adapter for the ShareGateway port (Lot 2 — integration).
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Sharing;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * Adapts ShareGateway onto OCP\Share\IManager.
 *
 * Thin translation only — all policy (owner-only, level → permissions, share
 * type validation) lives in BoardShareService. This class is NOT unit-tested:
 * OCP interfaces aren't loadable outside a real Nextcloud, and mocking IManager
 * would only assert we know its signatures, not that Nextcloud obeys. It is
 * validated on staging with two accounts (Lot 4).
 *
 * NOTE — the invitee-side mount under Kanban/ (decision §10.1, MountPointResolver)
 * is a separate, invitee-side step: a received share lands at the recipient's
 * Files root, and where a board already exists there is only knowable from the
 * invitee's folder — not the sharer's. This adapter only creates/lists/revokes
 * shares from the owner side. Mounting is a follow-up sub-lot.
 */
final class NextcloudShareGateway implements ShareGateway {

	private const KANBAN_ROOT = 'Kanban';

	/** Our share-type names → OCP\Share\IShare type constants (verified NC 34). */
	private const TYPE_TO_NC = [
		'user' => IShare::TYPE_USER,
		'group' => IShare::TYPE_GROUP,
		'team' => IShare::TYPE_CIRCLE,
	];

	public function __construct(
		private readonly IManager $shareManager,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
	) {
	}

	public function currentUserOwns(string $boardId): bool {
		try {
			$this->boardNode($boardId);

			return true;
		} catch (NotFoundException) {
			return false;
		}
	}

	public function share(string $boardId, string $shareType, string $shareWith, int $permissions): string {
		if (!isset(self::TYPE_TO_NC[$shareType])) {
			throw new \InvalidArgumentException('Unsupported share type: ' . $shareType);
		}

		$share = $this->shareManager->newShare();
		$share->setNode($this->boardNode($boardId));
		$share->setShareType(self::TYPE_TO_NC[$shareType]);
		$share->setSharedWith($shareWith);
		$share->setSharedBy($this->uid());
		$share->setPermissions($permissions);

		return $this->shareManager->createShare($share)->getId();
	}

	public function listShares(string $boardId): array {
		$node = $this->boardNode($boardId);
		$uid = $this->uid();
		$out = [];

		foreach (self::TYPE_TO_NC as $name => $ncType) {
			foreach ($this->shareManager->getSharesBy($uid, $ncType, $node, false, 500) as $share) {
				$out[] = [
					'id' => $share->getId(),
					'type' => $name,
					'with' => (string) $share->getSharedWith(),
					'permissions' => (int) $share->getPermissions(),
				];
			}
		}

		return $out;
	}

	public function revoke(string $shareId): void {
		$this->shareManager->deleteShare($this->shareManager->getShareById($shareId));
	}

	/**
	 * The current user's board folder, or NotFoundException if absent/not a folder.
	 *
	 * getUserFolder() returns the CURRENT user's home, so finding the board there
	 * is exactly what "owns it" means.
	 */
	private function boardNode(string $boardId): Folder {
		$node = $this->rootFolder->getUserFolder($this->uid())->get(self::KANBAN_ROOT . '/' . $boardId);
		if (!$node instanceof Folder) {
			throw new NotFoundException('Not a board folder: ' . $boardId);
		}

		return $node;
	}

	private function uid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No current user');
		}

		return $user->getUID();
	}
}
