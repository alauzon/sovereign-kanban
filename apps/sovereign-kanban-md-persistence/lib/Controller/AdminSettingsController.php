<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Controller;

use OCA\SovereignKanbanMdPersistence\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Persists the Sovereign Kanban admin settings.
 *
 * No NoAdminRequired attribute on purpose: only instance admins may change
 * the Kanban sharing policy.
 */
final class AdminSettingsController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct('sovereign-kanban-md-persistence', $request);
	}

	/**
	 * Update the recipient-suggestion mode.
	 *
	 * @param string $suggestionMode One of AdminSettings::SUGGESTION_MODES.
	 */
	public function update(string $suggestionMode): DataResponse {
		if (!in_array($suggestionMode, AdminSettings::SUGGESTION_MODES, true)) {
			return new DataResponse(['error' => 'invalid_mode'], 400);
		}
		$this->appConfig->setValueString(
			'sovereign-kanban-md-persistence',
			AdminSettings::SUGGESTION_MODE_KEY,
			$suggestionMode,
		);

		return new DataResponse(['suggestionMode' => $suggestionMode]);
	}
}
