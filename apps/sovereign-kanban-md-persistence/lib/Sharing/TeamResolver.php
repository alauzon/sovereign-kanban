<?php

/**
 * @file
 * Expand a Team (Circles) share into the user accounts it reaches.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Fable 5)
 */

namespace OCA\SovereignKanbanMdPersistence\Sharing;

use OCA\Circles\CirclesManager;
use OCA\Circles\Model\Member;
use OCA\Circles\Model\Probes\DataProbe;
use OCP\App\IAppManager;
use OCP\Server;

/**
 * Resolves a Team id (Circles singleId) into its local user members.
 *
 * The circles app has no public OCP namespace, so this wrapper follows Deck's
 * proven pattern (deck/lib/Service/CirclesService.php): guard on the app being
 * enabled, resolve CirclesManager lazily — constructor injection would break DI
 * whenever circles is disabled — and swallow every Circles failure into an
 * empty result. A board shared to a Team must degrade to « no extra members »,
 * never to a 500 on the mention picker.
 */
final class TeamResolver {

	private readonly bool $circlesEnabled;

	public function __construct(IAppManager $appManager) {
		$this->circlesEnabled = $appManager->isEnabledForUser('circles');
	}

	/**
	 * The local user accounts a Team share reaches, mention-ready.
	 *
	 * Nested circles are followed (inherited members); only confirmed local
	 * user members are kept — pending invitations, groups-as-members and
	 * remote instances are not mentionable accounts.
	 *
	 * @param string $teamId The circle singleId a share's 'with' carries.
	 *
	 * @return array<string,string> uid => display name; empty when circles is
	 *   disabled or the circle cannot be resolved.
	 */
	public function memberUids(string $teamId): array {
		if (!$this->circlesEnabled || $teamId === '') {
			return [];
		}

		try {
			$circlesManager = Server::get(CirclesManager::class);
			// Super session: the full member list, independent of who asks —
			// the access set is decided by the share, not by circle visibility.
			$circlesManager->startSuperSession();
			$probe = new DataProbe();
			$probe->add(DataProbe::OWNER);
			$circle = $circlesManager->probeCircle($teamId, null, $probe);

			$out = [];
			foreach ($circle->getInheritedMembers() as $member) {
				if ($member->getUserType() !== Member::TYPE_USER
					|| $member->getLevel() < Member::LEVEL_MEMBER) {
					continue;
				}
				$uid = $member->getUserId();
				$out[$uid] = $member->getDisplayName() ?: $uid;
			}

			return $out;
		} catch (\Throwable) {
			return [];
		}
	}
}
