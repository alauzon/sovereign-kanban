<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Kanban;

use OCA\SovereignKanbanMdPersistence\Kanban\TemplateLibrary;
use OCA\SovereignKanbanMdPersistence\Storage\LocalStorage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the template/procedure library.
 *
 * @group sovereign-kanban
 */
final class TemplateLibraryTest extends TestCase {

	private string $dir;
	private TemplateLibrary $lib;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/sk-tpl-' . uniqid('', true);
		mkdir($this->dir, 0777, true);
		$this->lib = new TemplateLibrary(new LocalStorage($this->dir));
	}

	protected function tearDown(): void {
		$this->rrmdir($this->dir);
	}

	public function testSeedsDefaultTemplatesWhenFolderAbsent(): void {
		$templates = $this->lib->templates();

		$names = array_map(static fn (array $t): string => $t['name'], $templates);
		$this->assertContains('Réunion sociocratique', $names);
		$this->assertContains('Compte-rendu de réunion', $names);
		$this->assertContains('Rencontre en 4 temps (SdP)', $names);
		// Seeded as real .md files on disk (portable, editable anywhere).
		$this->assertFileExists($this->dir . '/Modèles/Réunion sociocratique.md');
	}

	public function testSeedsDefaultProcedures(): void {
		$names = array_map(static fn (array $p): string => $p['name'], $this->lib->procedures());

		$this->assertContains('Élection sans candidat', $names);
		$this->assertContains('Décision par consentement', $names);
		$this->assertContains("Récolte d'objections", $names);
	}

	public function testParsesFrontmatterAndStripsItFromBody(): void {
		$meeting = null;
		foreach ($this->lib->templates() as $t) {
			if ($t['name'] === 'Réunion sociocratique') {
				$meeting = $t;
			}
		}

		$this->assertNotNull($meeting);
		$this->assertSame('En cours', $meeting['meta']['colonne_cible']);
		$this->assertStringNotContainsString('gabarit:', $meeting['body']);
		$this->assertStringContainsString('### Rôles', $meeting['body']);
	}

	public function testDoesNotReseedWhenFolderAlreadyExists(): void {
		mkdir($this->dir . '/Modèles', 0777, true);
		file_put_contents($this->dir . '/Modèles/Mon gabarit.md', "## Custom\n");

		$templates = $this->lib->templates();

		$this->assertCount(1, $templates);
		$this->assertSame('Mon gabarit', $templates[0]['name']);
	}

	private function rrmdir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
