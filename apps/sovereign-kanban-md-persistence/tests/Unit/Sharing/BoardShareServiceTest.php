<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Sharing;

use OCA\SovereignKanbanMdPersistence\Sharing\BoardShareService;
use OCA\SovereignKanbanMdPersistence\Sharing\NotBoardOwnerException;
use OCA\SovereignKanbanMdPersistence\Sharing\ShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ShareNotOnBoardException;
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
}
