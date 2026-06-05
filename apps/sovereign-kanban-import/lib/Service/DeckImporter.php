<?php
/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanImport\Service;

use OCP\DB\IDBConnection;
use OCP\IUserManager;
use OCA\SovereignKanbanMdPersistence\Kanban\Card;
use OCA\SovereignKanbanMdPersistence\Kanban\FileCardRepository;

/**
 * Service to import Deck boards and cards to Sovereign Kanban.
 */
final class DeckImporter {

	private FileCardRepository $repository;

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
	) {
		// Initialize repository pointing to Kanban directory
		$userFolder = $this->getDefaultUserFolder();
		$this->repository = new FileCardRepository($userFolder->getPath() . '/Kanban');
	}

	/**
	 * Import all Deck boards to Sovereign Kanban format.
	 *
	 * @return array{boards: int, cards: int}
	 */
	public function import(): array {
		$boardCount = 0;
		$cardCount = 0;

		// Query Deck boards
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('deck_boards');
		$result = $query->execute();
		$boards = $result->fetchAll();

		foreach ($boards as $board) {
			$boardId = $board['id'];
			$boardTitle = $board['title'];

			// Create board directory
			$boardDir = $this->repository->getBasePath() . '/' . $boardTitle;
			if (!is_dir($boardDir)) {
				mkdir($boardDir, 0755, true);
			}

			// Query stacks (columns) for this board
			$stackQuery = $this->db->getQueryBuilder();
			$stackQuery->select('*')->from('deck_stacks')
				->where($stackQuery->expr()->eq('board_id', $boardId));
			$stackResult = $stackQuery->execute();
			$stacks = $stackResult->fetchAll();

			foreach ($stacks as $stack) {
				$stackId = $stack['id'];
				$stackTitle = $stack['title'];

				// Create column directory
				$columnDir = $boardDir . '/' . $stackTitle;
				if (!is_dir($columnDir)) {
					mkdir($columnDir, 0755, true);
				}

				// Query cards for this stack
				$cardQuery = $this->db->getQueryBuilder();
				$cardQuery->select('*')->from('deck_cards')
					->where($cardQuery->expr()->eq('stack_id', $stackId))
					->orderBy('order', 'ASC');
				$cardResult = $cardQuery->execute();
				$cards = $cardResult->fetchAll();

				foreach ($cards as $deckCard) {
					$card = new Card(
						id: (string)$deckCard['id'],
						title: $deckCard['title'],
						column: $stackTitle,
						description: $deckCard['description'] ?? '',
						created_at: new \DateTime($deckCard['created_at']),
						assignees: $this->getAssignees($deckCard['id']),
					);

					$this->repository->save($card);
					$cardCount++;
				}
			}

			$boardCount++;
		}

		return [
			'boards' => $boardCount,
			'cards' => $cardCount,
		];
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

		return array_column($rows, 'user_id');
	}

	/**
	 * Get default user folder (fallback to admin).
	 *
	 * @return \OCP\Files\Folder
	 */
	private function getDefaultUserFolder() {
		$user = $this->userManager->get('admin');
		if (!$user) {
			$users = $this->userManager->search('');
			$user = reset($users);
		}

		// This is simplified — in production, we'd use proper AppData
		return new \stdClass(); // Placeholder
	}
}
