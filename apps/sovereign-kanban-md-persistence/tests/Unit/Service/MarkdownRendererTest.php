<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Service;

use OCA\SovereignKanbanMdPersistence\Service\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Markdown → safe HTML renderer used by previews.
 */
final class MarkdownRendererTest extends TestCase {

	private MarkdownRenderer $renderer;

	protected function setUp(): void {
		$this->renderer = new MarkdownRenderer();
	}

	public function testRendersBoldToStrong(): void {
		$this->assertStringContainsString('<strong>gras</strong>', $this->renderer->toHtml('Ceci est **gras**.'));
	}

	public function testRendersHeading(): void {
		$this->assertStringContainsString('<h1>Titre</h1>', $this->renderer->toHtml('# Titre'));
	}

	public function testRendersGfmTable(): void {
		$md = "| A | B |\n|---|---|\n| 1 | 2 |";
		$this->assertStringContainsString('<table>', $this->renderer->toHtml($md));
	}

	public function testEscapesRawHtmlToPreventXss(): void {
		$html = $this->renderer->toHtml('<script>alert(1)</script>');
		$this->assertStringNotContainsString('<script>', $html);
	}

	public function testEmptyForBlankInput(): void {
		$this->assertSame('', $this->renderer->toHtml("   \n  "));
	}
}
