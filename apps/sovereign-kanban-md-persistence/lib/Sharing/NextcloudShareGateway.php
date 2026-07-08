<?php

/**
 * @file
 * Nextcloud adapter for the ShareGateway port (Lot 2 — integration).
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Sharing;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Symfony\Component\Yaml\Yaml;

/**
 * Adapts ShareGateway onto OCP\Share\IManager.
 *
 * Thin translation only — all policy (owner-only, level → permissions, share
 * type validation) lives in BoardShareService. This class is NOT unit-tested:
 * OCP interfaces aren't loadable outside a real Nextcloud, and mocking IManager
 * would only assert we know its signatures, not that Nextcloud obeys. It is
 * validated on staging with two accounts (Lot 4).
 *
 * NOTE — invitee-side received boards (spike-validated 2026-07-08, spec §12):
 * a received share lands at the recipient's Files ROOT, not under Kanban/, and
 * setTarget is unreliable on NC 34 (updateShare throws). So the invitee side
 * LISTS received shares (Option B, follow-up sub-lot 2b: getSharedWith +
 * .board.yml filter) rather than mounting. This adapter only creates/lists/
 * revokes shares from the owner side.
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

		// getFullId() ('ocinternal:11255'), not getId() ('11255'): getShareById
		// (used by revoke) needs the provider-prefixed id — staging showed getId
		// yields ShareNotFound. §12.
		return $this->shareManager->createShare($share)->getFullId();
	}

	public function listShares(string $boardId): array {
		$node = $this->boardNode($boardId);
		$uid = $this->uid();
		$out = [];

		foreach (self::TYPE_TO_NC as $name => $ncType) {
			foreach ($this->shareManager->getSharesBy($uid, $ncType, $node, false, 500) as $share) {
				$out[] = [
					'id' => $share->getFullId(),
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
	 * Boards shared TO the current user, across user/group/team channels.
	 *
	 * Spike-validated (spec §12): getSharedWith lists the received share,
	 * getNode() gives the shared folder, and its .board.yml is readable.
	 * getSharedBy() is the owner. May yield duplicates (direct + group) — the
	 * service deduplicates.
	 */
	public function receivedBoards(): array {
		$uid = $this->uid();
		$out = [];

		foreach (self::TYPE_TO_NC as $ncType) {
			foreach ($this->shareManager->getSharedWith($uid, $ncType, null, 500) as $share) {
				$node = $share->getNode();
				if (!$node instanceof Folder || !$node->nodeExists('.board.yml')) {
					continue;
				}
				$file = $node->get('.board.yml');
				$data = $file instanceof File ? (Yaml::parse($file->getContent()) ?? []) : [];
				$out[] = [
					'id' => (string) ($data['id'] ?? $node->getName()),
					'name' => (string) ($data['name'] ?? $node->getName()),
					'owner' => (string) $share->getSharedBy(),
					'permissions' => (int) $share->getPermissions(),
				];
			}
		}

		return $out;
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
