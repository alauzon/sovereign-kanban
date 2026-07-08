<?php

/**
 * @file
 * Resolves where a received shared board mounts in the invitee's Files.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Sharing;

/**
 * Decides the mount point of a received shared board (decision §10.1).
 *
 * Received shares are mounted under the invitee's Kanban/ so the existing board
 * scan finds them. On a slug collision with a board the invitee already has, a
 * `-partagé` then `-partagé-{owner}` suffix keeps them distinct — a wrong
 * resolution would surface someone else's board, so this is security-relevant
 * and lives here as pure, tested logic (not in the Nextcloud adapter).
 */
final class MountPointResolver {

	private const ROOT = 'Kanban';

	/**
	 * Resolve the relative mount path for a received board.
	 *
	 * @param string $slug Shared board slug.
	 * @param string $ownerId Owner uid, used as the last-resort disambiguator.
	 * @param list<string> $existing Slugs already present in the invitee's Kanban/.
	 *
	 * @return string Relative path, e.g. "Kanban/bienvenue" or
	 *   "Kanban/bienvenue-partagé".
	 */
	public function resolve(string $slug, string $ownerId, array $existing): string {
		foreach ([$slug, $slug . '-partagé', $slug . '-partagé-' . $ownerId] as $candidate) {
			if (!in_array($candidate, $existing, true)) {
				return self::ROOT . '/' . $candidate;
			}
		}

		// Everything taken (extremely unlikely) — number it until free.
		$n = 2;
		while (in_array($slug . '-partagé-' . $ownerId . '-' . $n, $existing, true)) {
			$n++;
		}

		return self::ROOT . '/' . $slug . '-partagé-' . $ownerId . '-' . $n;
	}
}
