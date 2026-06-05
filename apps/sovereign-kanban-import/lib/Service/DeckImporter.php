<?php
/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanImport\Service;

use OCP\DB\IDBConnection;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;

/**
 * Service to import Deck boards and cards to Sovereign Kanban.
 */
final class DeckImporter {

	private FileCardRepository $repository;
	private string $kanbanBasePath;

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * Import all Deck boards to Sovereign Kanban format.
	 *
	 * @param string $userId User ID to import for (default: first admin)
	 * @return array{boards: int, cards: int, errors: array<string>}
	 * @throws \Exception if import fails
	 */
	public function import(string $userId = ''): array {
		if (!$userId) {
			$userId = $this->getDefaultUserId();
		}

		$this->initializeRepository($userId);
		$boardCount = 0;
		$cardCount = 0;
		$errors = [];

		try {
			// Query Deck boards
			$boards = $this->queryBoards();

			foreach ($boards as $board) {
				try {
					$boardId = (int)$board['id'];
					$boardTitle = (string)$board['title'];

					// Create board directory
					$boardDir = $this->kanbanBasePath . '/' . $boardTitle;
					if (!is_dir($boardDir)) {
						mkdir($boardDir, 0755, true);
					}

					// Query stacks (columns) for this board
					$stacks = $this->queryStacks($boardId);

					foreach ($stacks as $stack) {
						try {
							$stackId = (int)$stack['id'];
							$stackTitle = (string)$stack['title'];

							// Create column directory
							$columnDir = $boardDir . '/' . $stackTitle;
							if (!is_dir($columnDir)) {
								mkdir($columnDir, 0755, true);
							}

							// Query cards for this stack
							$cards = $this->queryCards($stackId);

							foreach ($cards as $deckCard) {
								try {
									$card = $this->createCard($deckCard, $stackTitle);
									$this->repository->save($card);
									$cardCount++;
								} catch (\Exception $e) {
									$errors[] = "Card {$deckCard['id']}: {$e->getMessage()}";
								}
							}
						} catch (\Exception $e) {
							$errors[] = "Stack {$stackId}: {$e->getMessage()}";
						}
					}

					$boardCount++;
				} catch (\Exception $e) {
					$errors[] = "Board {$boardId}: {$e->getMessage()}";
				}
			}
		} catch (\Exception $e) {
			throw new \Exception("Import failed: {$e->getMessage()}");
		}

		return [
			'boards' => $boardCount,
			'cards' => $cardCount,
			'errors' => $errors,
		];
	}

	/**
	 * Query all Deck boards.
	 *
	 * @return array<array>
	 */
	private function queryBoards(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('id', 'title')->from('deck_boards');
		$result = $query->execute();
		return $result->fetchAll();
	}

	/**
	 * Query stacks (columns) for a board.
	 *
	 * @param int $boardId
	 * @return array<array>
	 */
	private function queryStacks(int $boardId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('id', 'title')->from('deck_stacks')
			->where($query->expr()->eq('board_id', $boardId))
			->orderBy('order', 'ASC');
		$result = $query->execute();
		return $result->fetchAll();
	}

	/**
	 * Query cards for a stack.
	 *
	 * @param int $stackId
	 * @return array<array>
	 */
	private function queryCards(int $stackId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('id', 'title', 'description', 'created_at')->from('deck_cards')
			->where($query->expr()->eq('stack_id', $stackId))
			->orderBy('order', 'ASC');
		$result = $query->execute();
		return $result->fetchAll();
	}

	/**
	 * Create a Card from Deck card data.
	 *
	 * @param array $deckCard
	 * @param string $column
	 * @return Card
	 */
	private function createCard(array $deckCard, string $column): Card {
		$cardId = (int)$deckCard['id'];
		$createdAt = new \DateTime($deckCard['created_at'] ?? 'now');

		return new Card(
			id: (string)$cardId,
			title: (string)$deckCard['title'],
			column: $column,
			description: (string)($deckCard['description'] ?? ''),
			created_at: $createdAt,
			assignees: $this->getAssignees($cardId),
		);
	}

	/**
	 * Get assignees for a Deck card.
	 *
	 * @param int $cardId
	 * @return array<string>
	 */
	private function getAssignees(int $cardId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('user_id')->from('deck_card_assignees')
			->where($query->expr()->eq('card_id', $cardId));
		$result = $query->execute();
		$rows = $result->fetchAll();

		return array_column($rows, 'user_id') ?: [];
	}

	/**
	 * Get default user ID (first admin).
	 *
	 * @return string
	 * @throws \Exception if no user found
	 */
	private function getDefaultUserId(): string {
		$user = $this->userManager->get('admin');
		if ($user) {
			return $user->getUID();
		}

		$users = $this->userManager->search('', 1);
		if (!empty($users)) {
			$user = reset($users);
			return $user->getUID();
		}

		throw new \Exception('No users found');
	}

	/**
	 * Initialize the FileCardRepository for a user.
	 *
	 * @param string $userId
	 * @throws \Exception if user folder not accessible
	 */
	private function initializeRepository(string $userId): void {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$kanbanDir = $userFolder->getPath() . '/Kanban';

			// Create Kanban directory if it doesn't exist
			if (!$userFolder->nodeExists('Kanban')) {
				$userFolder->newFolder('Kanban');
			}

			$this->kanbanBasePath = $kanbanDir;
			$this->repository = new FileCardRepository($kanbanDir);
		} catch (\Exception $e) {
			throw new \Exception("Cannot access user folder for $userId: {$e->getMessage()}");
		}
	}
}
