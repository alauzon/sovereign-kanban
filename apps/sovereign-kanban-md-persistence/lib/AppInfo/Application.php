<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\AppInfo;

use OCP\AppFramework\App;

/**
 * Sovereign Kanban persistence backend bootstrap.
 *
 * Loads the app's bundled Composer dependencies (ramsey/uuid, symfony/yaml)
 * before Nextcloud instantiates any controller that needs them.
 */
final class Application extends App {

	public const APP_ID = 'sovereign-kanban-md-persistence';

	public function __construct() {
		$autoload = __DIR__ . '/../../vendor/autoload.php';
		if (is_file($autoload)) {
			require_once $autoload;
		}
		parent::__construct(self::APP_ID);
	}
}
