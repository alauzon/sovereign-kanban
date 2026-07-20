<?php

/**
 * @file
 * Contract for the card « linked_board » (carte-tableau, Alain 2026-07-20): a
 * project card that opens its own board. The field must survive the whole
 * value-object round-trip — serialize, re-parse, move (withColumn), relations,
 * toArray — because a single missing `new self()` copy drops it silently. Each
 * check falsifies one wiring point.
 *
 * Usage: runuser -u www-data -- php linked_board.php   ·   0 ok · 1 fail.
 */

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

use OCA\SovereignKanbanMdPersistence\Kanban\Card;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void {
	global $pass, $fail;
	echo ($ok ? "\e[32m✅\e[0m " : "\e[31m❌\e[0m ") . $label . "\n";
	$ok ? $pass++ : $fail++;
}

$c = new Card(
	id: '11111111-1111-4111-8111-111111111111',
	title: 'Projet X',
	column: '01-Backlog',
	linked_board: 'mon-projet-x',
);

$md = $c->toYAMLFrontmatter();
check('[1] linked_board écrit dans le frontmatter', str_contains($md, 'linked_board: mon-projet-x'));
check('[2] linked_board relu identique (fromMarkdown)', Card::fromMarkdown($md . "\n\ncorps")->linked_board === 'mon-projet-x');
check('[3] survit à withColumn (déplacement de carte)', $c->withColumn('02-En cours')->linked_board === 'mon-projet-x');
check('[4] survit à withRelations', $c->withRelations([['type' => 'related', 'card' => 'z']])->linked_board === 'mon-projet-x');
check('[5] présent dans toArray (réponse API)', ($c->toArray()['linked_board'] ?? 'ABSENT') === 'mon-projet-x');

$plain = new Card(id: '22222222-2222-4222-8222-222222222222', title: 'Simple', column: '01-Backlog');
check('[6] null par défaut', $plain->linked_board === null);
check('[7] absent du frontmatter quand null (écriture conditionnelle)', !str_contains($plain->toYAMLFrontmatter(), 'linked_board'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
