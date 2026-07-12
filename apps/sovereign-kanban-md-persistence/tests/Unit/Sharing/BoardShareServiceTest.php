<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Sharing;

use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NotBoardOwnerException;
use OCA\SovereignKanbanMdPersistence\Sharing\ShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ShareNotOnBoardException;
use OCA\SovereignKanbanMdPersistence\Sharing\SharePermissions;
use PHPUnit\Framework\TestCase;

/**
 * Lot 2 logic: owner-only sharing/revocation + level → permission dispatch,
 * exercised against a fake gateway so no OCP dependency is needed.
 *
 * @group sovereign-kanban
 */
final class BoardShareServiceTest extends TestCase {

	// --- share() ---------------------------------------------------------

	public function testNonOwnerCannotShare(): void {
		$service = new BoardShareService(new FakeShareGateway(owns: false));

		$this->expectException(NotBoardOwnerException::class);
		$service->share('projets-sdp', 'user', 'steve', 'read');
	}

	public function testCollaborateForwardsFullPermissionsToGateway(): void {
		$gateway = new FakeShareGateway(owns: true);
		$service = new BoardShareService($gateway);

		$shareId = $service->share('projets-sdp', 'user', 'steve', 'collaborate');

		$this->assertSame('share-1', $shareId);
		$this->assertSame(15, $gateway->lastPermissions, 'READ|UPDATE|CREATE|DELETE');
		$this->assertSame('user', $gateway->lastShareType);
		$this->assertSame('steve', $gateway->lastShareWith);
	}

	public function testReadForwardsReadOnlyToGateway(): void {
		$gateway = new FakeShareGateway(owns: true);
		$service = new BoardShareService($gateway);

		$service->share('projets-sdp', 'group', 'cercle', 'read');

		$this->assertSame(1, $gateway->lastPermissions, 'READ only');
	}

	public function testShareToTeamForwardsTeamType(): void {
		$gateway = new FakeShareGateway(owns: true);
		$service = new BoardShareService($gateway);

		$service->share('projets-sdp', 'team', 'cercle-x', 'read');

		$this->assertSame('team', $gateway->lastShareType);
	}

	public function testUnknownShareTypeIsRejected(): void {
		$service = new BoardShareService(new FakeShareGateway(owns: true));

		$this->expectException(\InvalidArgumentException::class);
		$service->share('projets-sdp', 'carrier-pigeon', 'x', 'read');
	}

	public function testServiceNeverForwardsTheShareBit(): void {
		// Invitees must never be able to re-share (decision §10.5), at every level.
		foreach (['read', 'collaborate'] as $level) {
			$gateway = new FakeShareGateway(owns: true);
			(new BoardShareService($gateway))->share('projets-sdp', 'user', 'steve', $level);
			$this->assertSame(0, $gateway->lastPermissions & 16, "SHARE bit set at level $level");
		}
	}

	// --- revoke() --------------------------------------------------------

	public function testNonOwnerCannotRevoke(): void {
		$service = new BoardShareService(new FakeShareGateway(owns: false));

		$this->expectException(NotBoardOwnerException::class);
		$service->revoke('projets-sdp', 'share-1');
	}

	public function testCannotRevokeShareThatIsNotOnTheBoard(): void {
		// Owner of board A must not revoke a share that doesn't belong to A,
		// even by guessing its id (Kate's owner-only hole on revoke).
		$gateway = new FakeShareGateway(owns: true);
		$gateway->seedShares('projets-sdp', [
			['id' => 'share-1', 'type' => 'user', 'with' => 'steve', 'permissions' => 1],
		]);
		$service = new BoardShareService($gateway);

		$this->expectException(ShareNotOnBoardException::class);
		$service->revoke('projets-sdp', 'share-999');
	}

	public function testOwnerRevokesShareOnTheBoard(): void {
		$gateway = new FakeShareGateway(owns: true);
		$gateway->seedShares('projets-sdp', [
			['id' => 'share-1', 'type' => 'user', 'with' => 'steve', 'permissions' => 1],
		]);
		$service = new BoardShareService($gateway);

		$service->revoke('projets-sdp', 'share-1');

		$this->assertSame('share-1', $gateway->lastRevokedId);
	}

	// --- listShares() ----------------------------------------------------

	public function testNonOwnerCannotListShares(): void {
		$service = new BoardShareService(new FakeShareGateway(owns: false));

		$this->expectException(NotBoardOwnerException::class);
		$service->listShares('projets-sdp');
	}

	public function testOwnerListsBoardShares(): void {
		$gateway = new FakeShareGateway(owns: true);
		$gateway->seedShares('projets-sdp', [
			['id' => 'share-1', 'type' => 'user', 'with' => 'steve', 'permissions' => 1],
		]);
		$service = new BoardShareService($gateway);

		$shares = $service->listShares('projets-sdp');

		$this->assertCount(1, $shares);
		$this->assertSame('steve', $shares[0]['with']);
	}

	// --- receivedBoards() ------------------------------------------------

	public function testReceivedBoardsDeduplicatesById(): void {
		// Same board reaching the user directly AND via a group → listed once.
		// owns:false shows there is no owner check — these are the user's own
		// received shares.
		$gateway = new FakeShareGateway(owns: false);
		$gateway->seedReceived([
			['id' => 'projets', 'name' => 'Projets', 'owner' => 'alain', 'permissions' => 1],
			['id' => 'projets', 'name' => 'Projets', 'owner' => 'alain', 'permissions' => 15],
		]);
		$service = new BoardShareService($gateway);

		$received = $service->receivedBoards();

		$this->assertCount(1, $received);
		$this->assertSame('projets', $received[0]['id']);
		$this->assertSame('alain', $received[0]['owner']);
	}

	public function testReceivedBoardsUnionsPermissionsOfDuplicateShares(): void {
		// The live footgun (Steve, 2026-07-12): the same board reaches the user
		// read-only (direct) AND collaborate (group). Nextcloud grants the union;
		// keeping whichever share the backend listed first denies — or grants —
		// edit at the mercy of iteration order.
		$gateway = new FakeShareGateway(owns: false);
		$gateway->seedReceived([
			['id' => 'demo', 'name' => 'Demo', 'owner' => 'alain', 'permissions' => 1],
			['id' => 'demo', 'name' => 'Demo', 'owner' => 'alain', 'permissions' => 15],
		]);
		$service = new BoardShareService($gateway);

		$received = $service->receivedBoards();

		$this->assertCount(1, $received);
		$this->assertSame(15, $received[0]['permissions'], 'union of read + collaborate');
	}

	// --- receivedPermission() --------------------------------------------

	public function testReceivedPermissionDeniesWriteForAReadOnlyBoard(): void {
		// Regression (Steve, 2026-07-12): a read-only recipient could still edit
		// cards because the write path never consulted the share's granted
		// permission — it read the folder node, which resolves in the owner's
		// scope and reports full permissions. Controllers now gate on this value.
		$gateway = new FakeShareGateway(owns: false);
		$gateway->seedReceived([
			['id' => 'demo', 'name' => 'Demo', 'owner' => 'alain', 'permissions' => SharePermissions::READ],
		]);
		$service = new BoardShareService($gateway);

		$permission = $service->receivedPermission('demo');

		$this->assertNotNull($permission);
		$this->assertFalse(SharePermissions::allowsWrite($permission), 'read-only board must refuse writes');
	}

	public function testReceivedPermissionAllowsWriteForACollaborateBoard(): void {
		$gateway = new FakeShareGateway(owns: false);
		$gateway->seedReceived([
			['id' => 'demo', 'name' => 'Demo', 'owner' => 'alain', 'permissions' => SharePermissions::forLevel('collaborate')],
		]);
		$service = new BoardShareService($gateway);

		$this->assertTrue(SharePermissions::allowsWrite($service->receivedPermission('demo')));
	}

	public function testReceivedPermissionUnionsAcrossChannelsSoCollaborateWins(): void {
		// Same board, read-only direct + collaborate group → the recipient may
		// write (this is exactly what let Steve edit while in Nibiru).
		$gateway = new FakeShareGateway(owns: false);
		$gateway->seedReceived([
			['id' => 'demo', 'name' => 'Demo', 'owner' => 'alain', 'permissions' => 1],
			['id' => 'demo', 'name' => 'Demo', 'owner' => 'alain', 'permissions' => 15],
		]);
		$service = new BoardShareService($gateway);

		$this->assertTrue(SharePermissions::allowsWrite($service->receivedPermission('demo')));
	}

	public function testReceivedPermissionIsNullForABoardNotSharedToTheUser(): void {
		$service = new BoardShareService(new FakeShareGateway(owns: false));

		$this->assertNull($service->receivedPermission('nope'));
	}
}

/**
 * In-memory ShareGateway that records calls — the test double for the Nextcloud
 * adapter (validated separately on staging).
 */
final class FakeShareGateway implements ShareGateway {

	public ?int $lastPermissions = null;
	public ?string $lastShareType = null;
	public ?string $lastShareWith = null;
	public ?string $lastRevokedId = null;
	private int $counter = 0;

	/** @var array<string, list<array{id: string, type: string, with: string, permissions: int}>> */
	private array $shares = [];

	/** @var list<array{id: string, name: string, owner: string, permissions: int}> */
	private array $received = [];

	public function __construct(private bool $owns) {
	}

	/**
	 * @param list<array{id: string, type: string, with: string, permissions: int}> $shares
	 */
	public function seedShares(string $boardId, array $shares): void {
		$this->shares[$boardId] = $shares;
	}

	public function currentUserOwns(string $boardId): bool {
		return $this->owns;
	}

	public function share(string $boardId, string $shareType, string $shareWith, int $permissions): string {
		$this->lastShareType = $shareType;
		$this->lastShareWith = $shareWith;
		$this->lastPermissions = $permissions;

		return 'share-' . (++$this->counter);
	}

	public function listShares(string $boardId): array {
		return $this->shares[$boardId] ?? [];
	}

	public function revoke(string $shareId): void {
		$this->lastRevokedId = $shareId;
	}

	/** @param list<array{id: string, name: string, owner: string, permissions: int}> $received */
	public function seedReceived(array $received): void {
		$this->received = $received;
	}

	public function receivedBoards(): array {
		return $this->received;
	}
}
