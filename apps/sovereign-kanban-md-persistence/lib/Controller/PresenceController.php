<?php

/**
 * @file
 * Board presence (Alain, 2026-07-19): who is looking at a board right now. Kept
 * in the distributed cache (Redis), never in files — presence is ephemeral state,
 * not board data. Each viewer heartbeats; viewers seen in the last window are
 * returned. Degrades to "just me" if no distributed cache is configured.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanMdPersistence\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;

final class PresenceController extends Controller {

	/** Consider a viewer present if seen within this many seconds. */
	private const WINDOW = 30;

	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ICacheFactory $cacheFactory,
		private readonly ITimeFactory $time,
	) {
		parent::__construct('sovereign-kanban-md-persistence', $request);
	}

	/**
	 * Heartbeat: record that the current user is viewing $boardId, and return the
	 * viewers currently present (uids seen within the window).
	 */
	#[NoAdminRequired]
	public function heartbeat(string $boardId): DataResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || !preg_match('/^[a-z0-9-]+$/', $boardId)) {
			return new DataResponse(['viewers' => []]);
		}

		$cache = $this->cacheFactory->createDistributed('sk-presence-');
		$now = $this->time->getTime();

		$seen = $cache->get($boardId) ?? [];
		if (!is_array($seen)) {
			$seen = [];
		}
		$seen[$uid] = $now;
		// Drop the stale ones so the map cannot grow forever.
		$seen = array_filter($seen, fn (int $ts): bool => $ts >= $now - self::WINDOW);

		$cache->set($boardId, $seen, self::WINDOW * 2);

		return new DataResponse(['viewers' => array_keys($seen)]);
	}
}
