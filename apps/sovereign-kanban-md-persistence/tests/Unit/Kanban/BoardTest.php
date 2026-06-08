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

	public function testAddColumnAppends(): void {
		$board = new Board(id: 'b', name: 'B', color: '#000', columns: ['Backlog', 'Fait']);

		$updated = $board->addColumn('En cours');

		$this->assertSame(['Backlog', 'Fait', 'En cours'], $updated->columns);
		$this->assertSame(['Backlog', 'Fait'], $board->columns, 'original unchanged');
	}

	public function testRenameColumnReplacesInPlace(): void {
		$board = new Board(id: 'b', name: 'B', color: '#000', columns: ['Backlog', 'En cours', 'Fait']);

		$updated = $board->renameColumn('En cours', 'En traitement');

		$this->assertSame(['Backlog', 'En traitement', 'Fait'], $updated->columns);
	}

	public function testRemoveColumnDropsIt(): void {
		$board = new Board(id: 'b', name: 'B', color: '#000', columns: ['Backlog', 'En cours', 'Fait']);

		$updated = $board->removeColumn('En cours');

		$this->assertSame(['Backlog', 'Fait'], $updated->columns);
	}

	public function testWithColumnsReplacesOrder(): void {
		$board = new Board(id: 'b', name: 'B', color: '#000', columns: ['Backlog', 'En cours', 'Fait']);

		$updated = $board->withColumns(['Fait', 'Backlog', 'En cours']);

		$this->assertSame(['Fait', 'Backlog', 'En cours'], $updated->columns);
	}

	public function testToArrayShapesBoardForTheApi(): void {
		$board = new Board(
			id: 'projets-sdp',
			name: 'Projets SdP',
			color: '#e85444',
			columns: ['Backlog', 'En cours'],
			tags: [['name' => 'infra', 'color' => '#e9322d']],
		);

		$this->assertSame(
			[
				'id' => 'projets-sdp',
				'name' => 'Projets SdP',
				'color' => '#e85444',
				'columns' => ['Backlog', 'En cours'],
				'tags' => [['name' => 'infra', 'color' => '#e9322d']],
			],
			$board->toArray(),
		);
	}

	public function testNewBoardHasEmptyTagPalette(): void {
		$board = Board::create(name: 'Test', color: '#46ba61');

		$this->assertSame([], $board->tags);
	}

	public function testWithTagsReplacesThePalette(): void {
		$board = new Board(id: 'b', name: 'B', color: '#000');

		$tagged = $board->withTags([
			['name' => 'infra', 'color' => '#e9322d'],
			['name' => 'urgent', 'color' => '#0082c9'],
		]);

		$this->assertSame(
			[
				['name' => 'infra', 'color' => '#e9322d'],
				['name' => 'urgent', 'color' => '#0082c9'],
			],
			$tagged->tags,
		);
		$this->assertSame([], $board->tags, 'original unchanged');
	}

	public function testTagPaletteRoundTripsThroughYaml(): void {
		$board = new Board(
			id: 'b',
			name: 'B',
			color: '#000',
			columns: ['Backlog'],
			tags: [['name' => 'infra', 'color' => '#e9322d']],
		);

		$parsed = yaml_parse($board->toYaml());

		$this->assertSame(
			[['name' => 'infra', 'color' => '#e9322d']],
			$parsed['tags'],
		);
	}

	public function testWithNamePreservesTagPalette(): void {
		$board = new Board(
			id: 'b',
			name: 'B',
			color: '#000',
			tags: [['name' => 'x', 'color' => '#111']],
		);

		$this->assertSame(
			[['name' => 'x', 'color' => '#111']],
			$board->withName('B2')->tags,
		);
	}
}
