<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\AppInfo;

use OCA\SovereignKanbanMdPersistence\Sharing\NextcloudShareGateway;
use OCA\SovereignKanbanMdPersistence\Sharing\ShareGateway;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Sovereign Kanban persistence backend bootstrap.
 *
 * Loads the app's bundled Composer dependencies (ramsey/uuid, symfony/yaml)
 * before Nextcloud instantiates any controller that needs them, and binds the
 * sharing port to its Nextcloud adapter.
 */
final class Application extends App implements IBootstrap {

	public const APP_ID = 'sovereign-kanban-md-persistence';

	public function __construct() {
		$autoload = __DIR__ . '/../../vendor/autoload.php';
		if (is_file($autoload)) {
			require_once $autoload;
		}
		parent::__construct(self::APP_ID);
	}

	/**
	 * Bind ShareGateway → NextcloudShareGateway so BoardShareService (which
	 * depends on the port interface) auto-wires.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(ShareGateway::class, NextcloudShareGateway::class);
	}

	public function boot(IBootContext $context): void {
	}
}
