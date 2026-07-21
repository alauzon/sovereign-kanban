<?php

/**
 * @file
 * sk_kanban.php — CLI to read and drive Sovereign Kanban boards from outside the
 * web UI (project-tracking skill, Alain 2026-07-19). Runs as the web user and
 * goes through the SAME repository/Storage layer as the app, so it writes valid
 * card.md on encrypted or plain instances alike — no raw filesystem, no secret.
 *
 * Usage (on the target container):
 *   runuser -u www-data -- php sk_kanban.php --user "<uid>" <cmd> [args] [--json]
 *
 * Commands:
 *   boards
 *   board-create <name> [--columns "A,B,C"] [--color "#0082c9"]
 *   cards <boardId>
 *   add   <boardId> <column> <title> [--desc "..."] [--due "YYYY-MM-DD[ HH:MM]"] [--priority high|medium|low]
 *   move  <boardId> <cardId> <toColumn>
 *   done  <boardId> <cardId>                # move to the board's last column
 *   comment  <boardId> <cardId> [texte]        # liste, ou ajoute un commentaire
 *   priority <boardId> <cardId> <1-5|clear>    # 1 = urgent … 5 = bas
 *   phase    <boardId> <cardId> <1-4|clear>
 *   relate   <boardId> <cardId> <cibleId> <type>   # child|parent|depends|required|related
 *   tag      <boardId> <cardId> add|rm <étiquette>
 *
 * <cardId> may be the full UUID or its 6-char short prefix (as shown by `cards`).
 * Exit: 0 ok · 1 usage/not-found · 2 no such user · 70 crash.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\Comment;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;
use Ramsey\Uuid\Uuid;

require_once '/var/www/nextcloud/lib/base.php';
\OC_App::loadApp('sovereign-kanban-md-persistence');

// ---- tiny arg parser: positionals + --flag value + bare --json ----
$argv0 = $argv;
array_shift($argv0);
$opts = ['json' => false];
$pos = [];
for ($i = 0; $i < count($argv0); $i++) {
	$a = $argv0[$i];
	if ($a === '--json') {
		$opts['json'] = true;
	} elseif (str_starts_with($a, '--')) {
		$opts[substr($a, 2)] = $argv0[$i + 1] ?? '';
		$i++;
	} else {
		$pos[] = $a;
	}
}

function fail(string $msg, int $code = 1): never {
	fwrite(STDERR, $msg . "\n");
	exit($code);
}
function out(array $rows, bool $json): void {
	if ($json) {
		echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
		return;
	}
	foreach ($rows as $r) {
		echo implode('  ', array_map(static fn ($v) => is_array($v) ? implode(',', $v) : (string) $v, $r)) . "\n";
	}
}

$uid = $opts['user'] ?? '';
$cmd = $pos[0] ?? '';
if ($uid === '' || $cmd === '') {
	fail('usage: sk_kanban.php --user <uid> <boards|board-create|cards|add|move|done> ...');
}

$userManager = \OC::$server->get(\OCP\IUserManager::class);
if ($userManager->get($uid) === null) {
	fail("no such user: $uid", 2);
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($userManager->get($uid));
\OC_Util::setupFS($uid);

$root = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$kanban = $root->nodeExists('Kanban') ? $root->get('Kanban') : $root->newFolder('Kanban');
$boardRepo = new FileBoardRepository(new NextcloudStorage($kanban));

/** Open a board's card repository, or fail. */
function cardRepoFor($kanban, string $boardId): FileCardRepository {
	if (!$kanban->nodeExists($boardId)) {
		fail("no such board: $boardId", 1);
	}
	return new FileCardRepository(new NextcloudStorage($kanban->get($boardId)));
}

/** Resolve a card by full id or 6-char short prefix; returns the Card (whose ->column is its folder). */
function findCard(FileCardRepository $repo, string $idOrPrefix): Card {
	foreach ($repo->listByColumn() as $cards) {
		foreach ($cards as $c) {
			if ($c->id === $idOrPrefix || str_starts_with($c->id, $idOrPrefix)) {
				return $c;
			}
		}
	}
	fail("card not found: $idOrPrefix", 1);
}

/**
 * Copy a Card with a few fields overridden — the value object is immutable and has
 * no generic wither, and rebuilding it by hand field-by-field is how a field gets
 * silently dropped. array_key_exists so a null (clear) is honoured.
 */
function rebuild(Card $c, array $o): Card {
	return new Card(
		id: $c->id,
		title: $o['title'] ?? $c->title,
		column: $o['column'] ?? $c->column,
		description: $o['description'] ?? $c->description,
		created_at: $c->created_at,
		assignees: $o['assignees'] ?? $c->assignees,
		due_date: $c->due_date,
		procedures: $c->procedures,
		priority: array_key_exists('priority', $o) ? $o['priority'] : $c->priority,
		tags: $o['tags'] ?? $c->tags,
		phase: array_key_exists('phase', $o) ? $o['phase'] : $c->phase,
		start_date: $c->start_date,
		completed_at: $c->completed_at,
		author: $c->author,
		color: $c->color,
		archived: $c->archived,
		relations: $c->relations,
		linked_board: array_key_exists('linked_board', $o) ? $o['linked_board'] : $c->linked_board,
		extra: $c->extra,
	);
}

try {
	switch ($cmd) {
		case 'boards': {
			$rows = [];
			foreach ($boardRepo->list() as $b) {
				$rows[] = ['id' => $b->id, 'name' => $b->name, 'columns' => $b->columns];
			}
			out($rows, $opts['json']);
			break;
		}

		case 'board-create': {
			$name = $pos[1] ?? fail('usage: board-create <name> [--columns "A,B,C"] [--color "#hex"]');
			$color = $opts['color'] ?? '#0082c9';
			$board = Board::create($name, $color);
			if (!empty($opts['columns'])) {
				$cols = array_values(array_filter(array_map('trim', explode(',', $opts['columns']))));
				if ($cols !== []) {
					$board = $board->withColumns($cols);
				}
			}
			$boardRepo->create($board);
			out([['id' => $board->id, 'name' => $board->name, 'columns' => $board->columns]], $opts['json']);
			break;
		}

		case 'cards': {
			$boardId = $pos[1] ?? fail('usage: cards <boardId>');
			$repo = cardRepoFor($kanban, $boardId);
			$rows = [];
			foreach ($repo->listByColumn() as $col => $cards) {
				foreach ($cards as $c) {
					if ($c->archived !== null) {
						continue;
					}
					$rows[] = ['id' => substr($c->id, 0, 6), 'column' => $col, 'title' => $c->title, 'done' => $c->completed_at !== null];
				}
			}
			out($rows, $opts['json']);
			break;
		}

		case 'add': {
			$boardId = $pos[1] ?? fail('usage: add <boardId> <column> <title> [--desc ..] [--due ..] [--priority ..]');
			$column = $pos[2] ?? fail('add: <column> requis');
			$title = $pos[3] ?? fail('add: <title> requis');
			$repo = cardRepoFor($kanban, $boardId);
			$folder = $repo->resolveColumnFolder($column) ?? $repo->firstColumnFolder();
			if ($folder === null) {
				fail("colonne introuvable « $column » et le tableau n'a aucune colonne");
			}
			$due = !empty($opts['due']) ? Card::normalizeDate($opts['due']) : null;
			$card = new Card(
				id: Uuid::uuid4()->toString(),
				title: $title,
				column: $folder,
				description: $opts['desc'] ?? '',
				created_at: new DateTime(),
				due_date: $due,
				priority: $opts['priority'] ?? null,
				author: $uid,
			);
			$repo->save($card);
			$repo->appendActivity($card->id, 'created', $uid, ['title' => $title]);
			out([['id' => substr($card->id, 0, 6), 'full' => $card->id, 'column' => preg_replace('/^\d+-/', '', $folder), 'title' => $title]], $opts['json']);
			break;
		}

		case 'move': {
			$boardId = $pos[1] ?? fail('usage: move <boardId> <cardId> <toColumn>');
			$cardArg = $pos[2] ?? fail('move: <cardId> requis');
			$toClean = $pos[3] ?? fail('move: <toColumn> requis');
			$repo = cardRepoFor($kanban, $boardId);
			$card = findCard($repo, $cardArg);
			$toFolder = $repo->resolveColumnFolder($toClean) ?? fail("colonne cible introuvable: $toClean");
			$repo->moveCard($card->id, $card->column, $toFolder);
			$repo->appendActivity($card->id, 'moved', $uid, [
				'from' => preg_replace('/^\d+-/', '', $card->column),
				'to' => $toClean,
			]);
			out([['id' => substr($card->id, 0, 6), 'moved_to' => $toClean, 'title' => $card->title]], $opts['json']);
			break;
		}

		case 'done': {
			$boardId = $pos[1] ?? fail('usage: done <boardId> <cardId>');
			$cardArg = $pos[2] ?? fail('done: <cardId> requis');
			$board = $boardRepo->find($boardId) ?? fail("no such board: $boardId");
			// Prefer a column that MEANS done (Terminé/Fait/Livré/Done…); else the
			// last NON-archive column; else the very last. Never land in Archivé by
			// default (Alain, 2026-07-20). NB: array_key_last, not end(), because
			// Board::$columns is readonly.
			$cols = $board->columns;
			if ($cols === []) {
				fail('le tableau n\'a aucune colonne');
			}
			$isDone = static fn (string $c): bool => in_array(
				mb_strtolower(trim($c)),
				['terminé', 'terminée', 'termine', 'fait', 'faite', 'livré', 'livree', 'done', 'complété', 'completed'],
				true,
			);
			$isArchive = static fn (string $c): bool => str_starts_with(mb_strtolower(trim($c)), 'archiv');
			$last = null;
			foreach ($cols as $c) {
				if ($isDone($c)) {
					$last = $c;
					break;
				}
			}
			if ($last === null) {
				foreach (array_reverse($cols) as $c) {
					if (!$isArchive($c)) {
						$last = $c;
						break;
					}
				}
			}
			$last ??= $cols[array_key_last($cols)];
			$repo = cardRepoFor($kanban, $boardId);
			$card = findCard($repo, $cardArg);
			$toFolder = $repo->resolveColumnFolder($last) ?? fail("colonne « $last » introuvable");
			if ($card->column !== $toFolder) {
				$repo->moveCard($card->id, $card->column, $toFolder);
				$repo->appendActivity($card->id, 'moved', $uid, [
					'from' => preg_replace('/^\d+-/', '', $card->column),
					'to' => $last,
				]);
			}
			out([['id' => substr($card->id, 0, 6), 'done_in' => $last, 'title' => $card->title]], $opts['json']);
			break;
		}

		case 'comment': {
			$boardId = $pos[1] ?? fail('usage: comment <boardId> <cardId> [texte]');
			$cardArg = $pos[2] ?? fail('comment: <cardId> requis');
			$repo = cardRepoFor($kanban, $boardId);
			$card = findCard($repo, $cardArg);
			if (!isset($pos[3])) {
				$rows = [];
				foreach ($repo->listComments($card->id) as $cm) {
					$rows[] = ['date' => $cm->created_at->format('Y-m-d H:i'), 'author' => $cm->author, 'body' => str_replace("\n", ' ', $cm->body)];
				}
				out($rows, $opts['json']);
				break;
			}
			$repo->addComment($card->id, Comment::create($uid, $pos[3]));
			$repo->appendActivity($card->id, 'commented', $uid);
			out([['id' => substr($card->id, 0, 6), 'commented' => $card->title]], $opts['json']);
			break;
		}

		case 'priority': {
			$boardId = $pos[1] ?? fail('usage: priority <boardId> <cardId> <1-5|clear>');
			$cardArg = $pos[2] ?? fail('priority: <cardId> requis');
			$value = $pos[3] ?? fail('priority: 1-5 ou « clear »');
			$p = ($value === 'clear' || $value === '') ? null : $value;
			if ($p !== null && !in_array($p, ['1', '2', '3', '4', '5'], true)) {
				fail('priority: 1 (urgent) à 5 (bas), ou « clear »');
			}
			$repo = cardRepoFor($kanban, $boardId);
			$card = findCard($repo, $cardArg);
			$repo->update(rebuild($card, ['priority' => $p]));
			$repo->appendActivity($card->id, 'updated', $uid, ['fields' => ['priority']]);
			out([['id' => substr($card->id, 0, 6), 'priority' => $p ?? '—', 'title' => $card->title]], $opts['json']);
			break;
		}

		case 'phase': {
			$boardId = $pos[1] ?? fail('usage: phase <boardId> <cardId> <1-4|clear>');
			$cardArg = $pos[2] ?? fail('phase: <cardId> requis');
			$value = $pos[3] ?? fail('phase: 1-4 ou « clear »');
			$ph = ($value === 'clear' || $value === '') ? null : (int) $value;
			if ($ph !== null && ($ph < 1 || $ph > 4)) {
				fail('phase: 1 à 4, ou « clear »');
			}
			$repo = cardRepoFor($kanban, $boardId);
			$card = findCard($repo, $cardArg);
			$repo->update(rebuild($card, ['phase' => $ph]));
			$repo->appendActivity($card->id, 'updated', $uid, ['fields' => ['phase']]);
			out([['id' => substr($card->id, 0, 6), 'phase' => $ph ?? '—', 'title' => $card->title]], $opts['json']);
			break;
		}

		case 'relate': {
			$boardId = $pos[1] ?? fail('usage: relate <boardId> <cardId> <cibleId> <type>');
			$cardArg = $pos[2] ?? fail('relate: <cardId> requis');
			$targetArg = $pos[3] ?? fail('relate: <cibleId> requis');
			$type = $pos[4] ?? 'related';
			if (!array_key_exists($type, Card::RELATION_RECIPROCAL)) {
				fail('relate: type parmi ' . implode(', ', array_keys(Card::RELATION_RECIPROCAL)));
			}
			$repo = cardRepoFor($kanban, $boardId);
			$card = findCard($repo, $cardArg);
			$target = findCard($repo, $targetArg);
			$ok = $repo->addRelation($card->id, $target->id, $type);
			if ($ok) {
				// The reciprocal lands on the other card, so both journals record it.
				$repo->appendActivity($card->id, 'linked', $uid, ['type' => $type, 'card' => $target->id]);
				$repo->appendActivity($target->id, 'linked', $uid, ['type' => Card::RELATION_RECIPROCAL[$type], 'card' => $card->id]);
			}
			out([['id' => substr($card->id, 0, 6), 'type' => $type, 'cible' => substr($target->id, 0, 6), 'ok' => $ok ? 'oui' : 'non']], $opts['json']);
			break;
		}

		case 'tag': {
			$boardId = $pos[1] ?? fail('usage: tag <boardId> <cardId> add|rm <étiquette>');
			$cardArg = $pos[2] ?? fail('tag: <cardId> requis');
			$action = $pos[3] ?? fail('tag: « add » ou « rm » requis');
			$tag = $pos[4] ?? fail('tag: <étiquette> requise');
			$repo = cardRepoFor($kanban, $boardId);
			$card = findCard($repo, $cardArg);
			$tags = $card->tags;
			if ($action === 'add') {
				if (!in_array($tag, $tags, true)) {
					$tags[] = $tag;
				}
			} elseif ($action === 'rm') {
				$tags = array_filter($tags, static fn ($t) => $t !== $tag);
			} else {
				fail('tag: action « add » ou « rm »');
			}
			$repo->update(rebuild($card, ['tags' => array_values($tags)]));
			$repo->appendActivity($card->id, 'updated', $uid, ['fields' => ['tags']]);
			out([['id' => substr($card->id, 0, 6), 'tags' => array_values($tags), 'title' => $card->title]], $opts['json']);
			break;
		}

		default:
			fail("commande inconnue: $cmd");
	}
} catch (\Throwable $e) {
	fwrite(STDERR, 'ERREUR: ' . $e->getMessage() . "\n");
	exit(70);
}
