<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Sharing;

use OCA\SovereignKanbanMdPersistence\Sharing\MountPointResolver;
use PHPUnit\Framework\TestCase;

/**
 * Where a received shared board mounts in the invitee's Files (decision §10.1):
 * under Kanban/, with -partagé then -{owner} suffixes on collision. Pure logic —
 * a wrong resolution would mount someone else's board, so it is security-relevant.
 *
 * @group sovereign-kanban
 */
final class MountPointResolverTest extends TestCase {

	public function testNoCollisionReturnsPlainSlugUnderKanban(): void {
		$resolver = new MountPointResolver();

		$this->assertSame(
			'Kanban/bienvenue',
			$resolver->resolve('bienvenue', 'alain', ['projets', 'allo']),
		);
	}

	public function testCollisionAppendsPartage(): void {
		$resolver = new MountPointResolver();

		$this->assertSame(
			'Kanban/bienvenue-partagé',
			$resolver->resolve('bienvenue', 'alain', ['bienvenue']),
		);
	}

	public function testDoubleCollisionAppendsOwner(): void {
		$resolver = new MountPointResolver();

		$this->assertSame(
			'Kanban/bienvenue-partagé-alain',
			$resolver->resolve('bienvenue', 'alain', ['bienvenue', 'bienvenue-partagé']),
		);
	}
}
