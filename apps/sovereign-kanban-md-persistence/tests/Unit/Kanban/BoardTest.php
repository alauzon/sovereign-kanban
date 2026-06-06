<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Kanban;

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Board value object.
 *
 * @group sovereign-kanban
 */
final class BoardTest extends TestCase {

	public function testCreateBoardGeneratesSlugFromName(): void {
		$board = Board::create(name: 'Projets SdP', color: '#e85444');

		$this->assertSame('projets-sdp', $board->id);
		$this->assertSame('Projets SdP', $board->name);
		$this->assertSame('#e85444', $board->color);
	}

	public function testCreateBoardSlugifiesAccentsAndPunctuation(): void {
		$board = Board::create(name: "Cercle des Différences !", color: '#4488ee');

		$this->assertSame('cercle-des-differences', $board->id);
	}

	public function testNewBoardHasDefaultColumns(): void {
		$board = Board::create(name: 'Test', color: '#46ba61');

		$this->assertSame(
			['Backlog', 'En cours', 'Terminé', 'Archivé'],
			$board->columns,
		);
	}

	public function testBoardToYamlIsWellFormedAndComplete(): void {
		$board = new Board(
			id: 'projets-sdp',
			name: 'Projets SdP',
			color: '#e85444',
			columns: ['Backlog', 'En cours', 'Terminé'],
		);

		$yaml = $board->toYaml();
		$parsed = yaml_parse($yaml);

		$this->assertIsArray($parsed);
		$this->assertSame('projets-sdp', $parsed['id']);
		$this->assertSame('Projets SdP', $parsed['name']);
		$this->assertSame('#e85444', $parsed['color']);
		$this->assertSame(['Backlog', 'En cours', 'Terminé'], $parsed['columns']);
	}

	public function testWithNameReturnsNewBoardPreservingId(): void {
		$board = new Board(id: 'projets-sdp', name: 'Projets SdP', color: '#e85444');

		$renamed = $board->withName('Projets Serveurs du Peuple');

		// id (slug) reste stable même si le nom change — le dossier ne bouge pas.
		$this->assertSame('projets-sdp', $renamed->id);
		$this->assertSame('Projets Serveurs du Peuple', $renamed->name);
		// Immutabilité : l'original n'a pas changé.
		$this->assertSame('Projets SdP', $board->name);
	}

	public function testWithColorReturnsNewBoardPreservingRest(): void {
		$board = new Board(id: 'projets-sdp', name: 'Projets SdP', color: '#e85444');

		$recolored = $board->withColor('#4488ee');

		$this->assertSame('#4488ee', $recolored->color);
		$this->assertSame('projets-sdp', $recolored->id);
		$this->assertSame('Projets SdP', $recolored->name);
		$this->assertSame('#e85444', $board->color);
	}
}
