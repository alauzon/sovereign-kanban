<?php
/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'import#import', 'url' => '/api/v1/import', 'verb' => 'POST'],
	],
];
