<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders Markdown to safe HTML for card and comment previews.
 *
 * Raw HTML embedded in the Markdown is escaped (html_input=escape) and unsafe
 * links are stripped (allow_unsafe_links=false), so the resulting HTML can be
 * injected into the DOM without an extra sanitizer.
 */
final class MarkdownRenderer {

	private readonly MarkdownConverter $converter;

	public function __construct() {
		$environment = new Environment([
			'html_input' => 'escape',
			'allow_unsafe_links' => false,
		]);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new GithubFlavoredMarkdownExtension());
		$this->converter = new MarkdownConverter($environment);
	}

	/**
	 * Render Markdown to sanitized HTML.
	 *
	 * @param string $markdown The Markdown source.
	 *
	 * @return string Safe HTML, or '' when the input is empty or blank.
	 */
	public function toHtml(string $markdown): string {
		if (trim($markdown) === '') {
			return '';
		}

		return $this->converter->convert($markdown)->getContent();
	}
}
