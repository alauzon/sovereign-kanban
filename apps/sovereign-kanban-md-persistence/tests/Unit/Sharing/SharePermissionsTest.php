<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Sharing;

use OCA\SovereignKanbanMdPersistence\Sharing\SharePermissions;
use PHPUnit\Framework\TestCase;

/**
 * Maps a share level (read / collaborate) to a Nextcloud permission bitmask.
 *
 * The bit values mirror OCP\Constants::PERMISSION_* (stable in Nextcloud), so
 * this stays a pure unit with no OCP dependency and runs in the local suite.
 *
 * @group sovereign-kanban
 */
final class SharePermissionsTest extends TestCase {

	public function testReadIsReadOnly(): void {
		$this->assertSame(SharePermissions::READ, SharePermissions::forLevel('read'));
	}

	public function testCollaborateIsReadWriteCreateDelete(): void {
		$expected = SharePermissions::READ | SharePermissions::UPDATE
			| SharePermissions::CREATE | SharePermissions::DELETE;
		$this->assertSame($expected, SharePermissions::forLevel('collaborate'));
	}

	public function testShareBitIsNeverSet(): void {
		// Invitees must never be able to re-share (decision §10.5).
		$this->assertSame(0, SharePermissions::forLevel('read') & SharePermissions::SHARE);
		$this->assertSame(0, SharePermissions::forLevel('collaborate') & SharePermissions::SHARE);
	}

	public function testUnknownLevelThrows(): void {
		$this->expectException(\InvalidArgumentException::class);
		SharePermissions::forLevel('admin');
	}

	// --- allowsWrite() ---------------------------------------------------

	public function testReadOnlyDoesNotAllowWrite(): void {
		$this->assertFalse(SharePermissions::allowsWrite(SharePermissions::forLevel('read')));
	}

	public function testCollaborateAllowsWrite(): void {
		$this->assertTrue(SharePermissions::allowsWrite(SharePermissions::forLevel('collaborate')));
	}

	public function testAllowsWriteIsDrivenByTheUpdateBit(): void {
		$this->assertFalse(SharePermissions::allowsWrite(0));
		$this->assertFalse(SharePermissions::allowsWrite(SharePermissions::READ));
		$this->assertTrue(SharePermissions::allowsWrite(SharePermissions::UPDATE));
		// An owner-style full mask (incl. SHARE) still writes.
		$this->assertTrue(SharePermissions::allowsWrite(31));
	}
}
