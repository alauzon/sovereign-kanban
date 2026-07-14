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
	 * Update the recipient-suggestion policy (any subset of the three modes).
	 *
	 * @param ?string $suggestionMode People mode — one of AdminSettings::SUGGESTION_MODES.
	 * @param ?string $groupMode Group mode — one of AdminSettings::SCOPE_MODES.
	 * @param ?string $teamMode Team mode — one of AdminSettings::SCOPE_MODES.
	 */
	public function update(?string $suggestionMode = null, ?string $groupMode = null, ?string $teamMode = null): DataResponse {
		$writes = [];
		if ($suggestionMode !== null) {
			if (!in_array($suggestionMode, AdminSettings::SUGGESTION_MODES, true)) {
				return new DataResponse(['error' => 'invalid_mode'], 400);
			}
			$writes[AdminSettings::SUGGESTION_MODE_KEY] = $suggestionMode;
		}
		if ($groupMode !== null) {
			if (!in_array($groupMode, AdminSettings::SCOPE_MODES, true)) {
				return new DataResponse(['error' => 'invalid_mode'], 400);
			}
			$writes[AdminSettings::GROUP_MODE_KEY] = $groupMode;
		}
		if ($teamMode !== null) {
			if (!in_array($teamMode, AdminSettings::SCOPE_MODES, true)) {
				return new DataResponse(['error' => 'invalid_mode'], 400);
			}
			$writes[AdminSettings::TEAM_MODE_KEY] = $teamMode;
		}
		if ($writes === []) {
			return new DataResponse(['error' => 'nothing_to_update'], 400);
		}
		foreach ($writes as $key => $value) {
			$this->appConfig->setValueString('sovereign-kanban-md-persistence', $key, $value);
		}

		return new DataResponse($writes);
	}
}
