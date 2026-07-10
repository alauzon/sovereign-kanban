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
		['name' => 'board#addColumn', 'url' => '/api/v1/boards/{boardId}/columns', 'verb' => 'POST'],
		['name' => 'board#removeColumn', 'url' => '/api/v1/boards/{boardId}/columns', 'verb' => 'DELETE'],
		['name' => 'board#renameColumn', 'url' => '/api/v1/boards/{boardId}/columns/rename', 'verb' => 'PUT'],
		['name' => 'board#reorderColumns', 'url' => '/api/v1/boards/{boardId}/columns/reorder', 'verb' => 'PUT'],
		['name' => 'card#index', 'url' => '/api/v1/boards/{boardId}/cards', 'verb' => 'GET'],
		['name' => 'card#create', 'url' => '/api/v1/boards/{boardId}/cards', 'verb' => 'POST'],
		['name' => 'card#show', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}', 'verb' => 'GET'],
		['name' => 'card#update', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}', 'verb' => 'PUT'],
		['name' => 'card#destroy', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}', 'verb' => 'DELETE'],
		['name' => 'card#move', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}/move', 'verb' => 'PUT'],
		['name' => 'card#comments', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}/comments', 'verb' => 'GET'],
		['name' => 'card#addComment', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}/comments', 'verb' => 'POST'],
		['name' => 'card#updateComment', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}/comments/{commentId}', 'verb' => 'PUT'],
		['name' => 'card#destroyComment', 'url' => '/api/v1/boards/{boardId}/cards/{cardId}/comments/{commentId}', 'verb' => 'DELETE'],
		['name' => 'template#index', 'url' => '/api/v1/templates', 'verb' => 'GET'],
		['name' => 'template#procedures', 'url' => '/api/v1/procedures', 'verb' => 'GET'],
		['name' => 'share#sharees', 'url' => '/api/v1/sharees', 'verb' => 'GET'],
		['name' => 'share#index', 'url' => '/api/v1/boards/{boardId}/shares', 'verb' => 'GET'],
		['name' => 'share#create', 'url' => '/api/v1/boards/{boardId}/shares', 'verb' => 'POST'],
		['name' => 'share#destroy', 'url' => '/api/v1/boards/{boardId}/shares/{shareId}', 'verb' => 'DELETE'],
	],
];
