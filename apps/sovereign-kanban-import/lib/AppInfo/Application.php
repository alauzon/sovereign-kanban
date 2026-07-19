<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Modern bootstrap for the import app. NC 34 no longer supports appinfo/app.php,
 * so without this IBootstrap Application the app's autoloader/DI is not wired and
 * ImportController cannot be resolved — the import returned a 500 (« Import
 * impossible »). Mirrors the md-persistence app's Application.
 */

namespace OCA\SovereignKanbanImport\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {

	public const APP_ID = 'sovereign-kanban-import';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
