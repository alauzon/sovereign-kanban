<?php

namespace OCA\SovereignKanbanMdPersistence\Tests\Unit\Kanban;

use OCA\SovereignKanbanMdPersistence\Kanban\Comment;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Comment value object.
 *
 * @group sovereign-kanban
 */
final class CommentTest extends TestCase {

	public function testCreateSetsAuthorBodyTimestampAndId(): void {
		$comment = Comment::create('admin', 'Hello world');

		$this->assertSame('admin', $comment->author);
		$this->assertSame('Hello world', $comment->body);
		$this->assertInstanceOf(\DateTime::class, $comment->created_at);
		$this->assertNotSame('', $comment->id);
	}

	public function testToMarkdownRoundTripsWithId(): void {
		$comment = new Comment('c-1', 'alain', new \DateTime('2026-06-06T16:30:00Z'), "Ligne 1\nLigne 2");

		$parsed = Comment::parseAll($comment->toMarkdown());

		$this->assertCount(1, $parsed);
		$this->assertSame('c-1', $parsed[0]->id);
		$this->assertSame('alain', $parsed[0]->author);
		$this->assertSame("Ligne 1\nLigne 2", $parsed[0]->body);
	}

	public function testParseAllReadsMultipleCommentsInOrder(): void {
		$first = new Comment('a', 'admin', new \DateTime('2026-06-06T10:00:00Z'), 'First');
		$second = new Comment('b', 'alain', new \DateTime('2026-06-06T11:00:00Z'), 'Second');

		$parsed = Comment::parseAll($first->toMarkdown() . "\n" . $second->toMarkdown());

		$this->assertCount(2, $parsed);
		$this->assertSame('First', $parsed[0]->body);
		$this->assertSame('alain', $parsed[1]->author);
		$this->assertSame('Second', $parsed[1]->body);
	}

	public function testParseAllOnEmptyReturnsEmpty(): void {
		$this->assertSame([], Comment::parseAll(''));
	}

	public function testParseAllDerivesStableIdForLegacyCommentWithoutId(): void {
		$legacy = "<!-- sk-comment author=\"admin\" date=\"2026-06-06T10:00:00Z\" -->\nLegacy body\n";

		$a = Comment::parseAll($legacy);
		$b = Comment::parseAll($legacy);

		$this->assertCount(1, $a);
		$this->assertNotSame('', $a[0]->id);
		$this->assertSame($a[0]->id, $b[0]->id);
		$this->assertSame('Legacy body', $a[0]->body);
	}

	public function testToArrayForApi(): void {
		$comment = new Comment('c-9', 'admin', new \DateTime('2026-06-06T16:30:00Z'), 'Body');

		$this->assertSame(
			[
				'id' => 'c-9',
				'author' => 'admin',
				'created_at' => '2026-06-06T16:30:00Z',
				'body' => 'Body',
			],
			$comment->toArray(),
		);
	}
}
