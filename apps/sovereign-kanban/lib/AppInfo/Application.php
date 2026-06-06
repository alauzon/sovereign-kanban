<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanban\AppInfo;

use OCP\AppFramework\App;

/**
 * Sovereign Kanban application bootstrap.
 */
final class Application extends App {

	public const APP_ID = 'sovereign-kanban';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}
}
