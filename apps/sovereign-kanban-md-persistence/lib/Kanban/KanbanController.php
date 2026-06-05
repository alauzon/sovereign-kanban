<?php

/**
 * @file
 * KanbanController — REST API for Kanban operations.
 *
 * @author Alain Lauzon <alauzon@alainlauzon.com>
 * @generated Claude (Haiku 4.5)
 */

namespace OCA\SovereignKanbanMdPersistence\Kanban;

/**
 * REST Controller for Kanban board operations.
 */
final class KanbanController {

	public function __construct(
		private FileCardRepository $repository,
	) {
	}

	/**
	 * Get board structure (columns and cards).
	 *
	 * @return array Board data with columns and cards
	 */
	public function getBoards(): array {
		return [
			'columns' => [
				'01-Backlog' => [],
				'02-En cours' => [],
				'03-Terminé' => [],
			],
		];
	}

	/**
	 * Create a new card.
	 *
	 * @param array $payload Card data (title, column, description)
	 * @return array Created card data
	 */
	public function createCard(array $payload): array {
		$card = Card::create(
			title: $payload['title'],
			column: $payload['column'],
		);

		$card = new Card(
			id: $card->id,
			title: $card->title,
			column: $card->column,
			description: $payload['description'] ?? '',
			created_at: $card->created_at,
			assignees: $payload['assignees'] ?? [],
		);

		$this->repository->save($card);

		return [
			'id' => $card->id,
			'title' => $card->title,
			'column' => $card->column,
			'description' => $card->description,
			'created_at' => $card->created_at->format('Y-m-d\TH:i:s\Z'),
			'assignees' => $card->assignees,
		];
	}

	/**
	 * Move a card to another column.
	 *
	 * @param array $payload {cardId, fromColumn, toColumn}
	 * @return array Updated card data
	 */
	public function moveCard(array $payload): array {
		$this->repository->moveCard(
			$payload['cardId'],
			$payload['fromColumn'],
			$payload['toColumn'],
		);

		return [
			'id' => $payload['cardId'],
			'column' => $payload['toColumn'],
		];
	}

	/**
	 * Delete a card.
	 *
	 * @param array $payload {cardId, column}
	 * @return array Deletion status
	 */
	public function deleteCard(array $payload): array {
		$this->repository->delete($payload['cardId'], $payload['column']);

		return [
			'deleted' => true,
		];
	}
}
