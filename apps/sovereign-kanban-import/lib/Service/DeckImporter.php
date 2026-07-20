<?php

/**
 * @file
 * DeckImporter — migrate a user's Deck boards into Sovereign Kanban.
 *
 * Rewritten 2026-07-17. The previous version could not run: it passed a string
 * where FileCardRepository wants a Storage, imported `use OCP\DB\IDBConnection`
 * (which does not exist — it is OCP\IDBConnection), queried a table that does
 * not exist (`deck_card_assignees`; the real one is `deck_assigned_users`),
 * fed an int Unix timestamp to `new DateTime()`, never selected the due date,
 * had no owner filter, wrote via raw mkdir (fatal on an encrypted instance),
 * and used Deck's numeric ids as card ids. The contract it must now meet lives
 * in tests/Functional/deck_import_contract.php.
 *
 * Everything goes through the Storage abstraction, so the same code writes
 * correctly on an encrypted instance (ET) as on a plain one (the test bench,
 * Tshinanu). That is not an elegance — a raw filesystem write is invisible
 * plaintext that Nextcloud later fails to decrypt.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Opus 4.8)
 */

namespace OCA\SovereignKanbanImport\Service;

use OCA\SovereignKanbanMdPersistence\Kanban\Board;
use OCA\SovereignKanbanMdPersistence\Kanban\BoardAlreadyExistsException;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\FileBoardRepository;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Ramsey\Uuid\Uuid;

/**
 * Import the Deck boards a given user OWNS into their Sovereign Kanban folder.
 */
final class DeckImporter {

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IUserManager $userManager,
		private readonly IRootFolder $rootFolder,
	) {
	}

	/**
	 * Import the target user's own Deck boards.
	 *
	 * Scope (decided 2026-07-17): the boards the user OWNS, not deleted, not
	 * archived. Deck is multi-user; importing "all boards" would rake other
	 * people's boards into one person's folder.
	 *
	 * @param string $userId The user whose boards are imported and who receives them.
	 *
	 * @return array{boards: int, cards: int, skipped: list<string>, errors: list<string>}
	 *   `skipped` = titles of boards already present (re-import is idempotent, not
	 *   an error). `errors` = boards that genuinely failed. Keeping the two apart
	 *   is the whole point: folding "already exists" into `errors` made a second
	 *   import read as "3 erreur(s)" to the user (Alain, 2026-07-19).
	 */
	public function import(string $userId): array {
		if ($userId === '' || $this->userManager->get($userId) === null) {
			throw new \InvalidArgumentException('A valid target userId is required.');
		}

		$kanbanRoot = $this->kanbanRoot($userId);
		$boardRepo = new FileBoardRepository(new NextcloudStorage($kanbanRoot));

		$boardCount = 0;
		$cardCount = 0;
		$skipped = [];
		$errors = [];

		foreach ($this->ownedBoards($userId) as $deckBoard) {
			try {
				$cardCount += $this->importBoard($deckBoard, $kanbanRoot, $boardRepo);
				$boardCount++;
			} catch (BoardAlreadyExistsException $e) {
				$skipped[] = (string) $deckBoard['title'];
			} catch (\Throwable $e) {
				$errors[] = sprintf('Tableau « %s » : %s', $deckBoard['title'], $e->getMessage());
			}
		}

		return ['boards' => $boardCount, 'cards' => $cardCount, 'skipped' => $skipped, 'errors' => $errors];
	}

	/**
	 * The user's Kanban/ root folder, created if missing.
	 */
	private function kanbanRoot(string $userId): Folder {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$node = $userFolder->nodeExists('Kanban')
			? $userFolder->get('Kanban')
			: $userFolder->newFolder('Kanban');
		if (!($node instanceof Folder)) {
			throw new \RuntimeException('Kanban path is not a folder.');
		}

		return $node;
	}

	/**
	 * Create one SK board from a Deck board and fill it with cards.
	 *
	 * @param array{id: int, title: string, color: ?string} $deckBoard
	 *
	 * @return int Number of cards imported into this board.
	 */
	private function importBoard(array $deckBoard, Folder $kanbanRoot, FileBoardRepository $boardRepo): int {
		$stacks = $this->stacks((int) $deckBoard['id']);

		// Column names, in Deck's stack order. Cleaned only for folder safety.
		$columns = array_map(
			fn (array $s): string => $this->columnName($s['title']),
			$stacks,
		);

		$color = '#' . ($deckBoard['color'] ?: '0082c9');
		$board = Board::create((string) $deckBoard['title'], $color);
		if ($columns !== []) {
			$board = $board->withColumns($columns);
		}
		$boardRepo->create($board);

		$boardFolder = $kanbanRoot->get($board->id);
		if (!($boardFolder instanceof Folder)) {
			throw new \RuntimeException('Board folder missing after create.');
		}
		$cardRepo = new FileCardRepository(new NextcloudStorage($boardFolder));

		$cards = 0;
		foreach ($stacks as $index => $stack) {
			// The folder FileBoardRepository::create made for this stack.
			$columnFolder = sprintf('%02d-%s', $index + 1, $columns[$index]);
			foreach ($this->cards((int) $stack['id']) as $deckCard) {
				$cardRepo->save($this->toCard($deckCard, $columnFolder));
				$cards++;
			}
		}

		return $cards;
	}

	/**
	 * Build an SK Card from a Deck card row, in the given column folder.
	 *
	 * @param array{id: int, title: string, description: ?string, created_at: ?int, duedate: ?string, startdate: ?string} $row
	 */
	private function toCard(array $row, string $columnFolder): Card {
		$created = new \DateTime();
		if (!empty($row['created_at'])) {
			$created->setTimestamp((int) $row['created_at']);
		}

		$cardId = (int) $row['id'];

		return new Card(
			id: Uuid::uuid4()->toString(),
			title: (string) $row['title'],
			column: $columnFolder,
			description: (string) ($row['description'] ?? ''),
			created_at: $created,
			assignees: $this->assignees($cardId),
			due_date: Card::normalizeDate($row['duedate'] ?? null),
			tags: $this->labels($cardId),
			start_date: Card::normalizeDate($row['startdate'] ?? null),
		);
	}

	/**
	 * Deck boards the user owns, excluding deleted and archived.
	 *
	 * @return array<array{id: int, title: string, color: ?string}>
	 */
	private function ownedBoards(string $userId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'title', 'color')
			->from('deck_boards')
			->where($q->expr()->eq('owner', $q->createNamedParameter($userId)))
			->andWhere($q->expr()->eq('deleted_at', $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('archived', $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $q->executeQuery()->fetchAll();
	}

	/**
	 * A board's stacks (columns), in display order, excluding deleted.
	 *
	 * @return array<array{id: int, title: string}>
	 */
	private function stacks(int $boardId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'title')
			->from('deck_stacks')
			->where($q->expr()->eq('board_id', $q->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('deleted_at', $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('order', 'ASC');

		return $q->executeQuery()->fetchAll();
	}

	/**
	 * A stack's cards, in order, excluding deleted and archived.
	 *
	 * @return array<array{id: int, title: string, description: ?string, created_at: ?int, duedate: ?string, startdate: ?string}>
	 */
	private function cards(int $stackId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'title', 'description', 'created_at', 'duedate', 'startdate')
			->from('deck_cards')
			->where($q->expr()->eq('stack_id', $q->createNamedParameter($stackId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('deleted_at', $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('archived', $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('order', 'ASC');

		return $q->executeQuery()->fetchAll();
	}

	/**
	 * A card's assigned users (type 0), in assignment order.
	 *
	 * @return list<string>
	 */
	private function assignees(int $cardId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('participant')
			->from('deck_assigned_users')
			->where($q->expr()->eq('card_id', $q->createNamedParameter($cardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->eq('type', $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return array_map(static fn (array $r): string => (string) $r['participant'], $q->executeQuery()->fetchAll());
	}

	/**
	 * A card's Deck labels, by title, in assignment order — become SK tags.
	 *
	 * @return list<string>
	 */
	private function labels(int $cardId): array {
		$q = $this->db->getQueryBuilder();
		$q->select('l.title')
			->from('deck_assigned_labels', 'al')
			->innerJoin('al', 'deck_labels', 'l', $q->expr()->eq('al.label_id', 'l.id'))
			->where($q->expr()->eq('al.card_id', $q->createNamedParameter($cardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('al.id', 'ASC');

		return array_map(static fn (array $r): string => (string) $r['title'], $q->executeQuery()->fetchAll());
	}

	/**
	 * Make a Deck stack title safe as a column folder name component.
	 */
	private function columnName(string $title): string {
		$name = trim(str_replace('/', '-', $title));

		return $name === '' ? 'Colonne' : $name;
	}
}
