<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'board#index', 'url' => '/api/v1/boards', 'verb' => 'GET'],
		['name' => 'board#create', 'url' => '/api/v1/boards', 'verb' => 'POST'],
		['name' => 'board#update', 'url' => '/api/v1/boards/{boardId}', 'verb' => 'PUT'],
		['name' => 'board#destroy', 'url' => '/api/v1/boards/{boardId}', 'verb' => 'DELETE'],
		['name' => 'card#index', 'url' => '/api/v1/boards/{boardId}/cards', 'verb' => 'GET'],
		['name' => 'card#create', 'url' => '/api/v1/boards/{boardId}/cards', 'verb' => 'POST'],
		['name' => 'card#show', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}', 'verb' => 'GET'],
		['name' => 'card#update', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}', 'verb' => 'PUT'],
		['name' => 'card#destroy', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}', 'verb' => 'DELETE'],
		['name' => 'card#move', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}/move', 'verb' => 'PUT'],
		['name' => 'card#comments', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}/comments', 'verb' => 'GET'],
		['name' => 'card#addComment', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}/comments', 'verb' => 'POST'],
	],
];
