<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Admin settings panel for Sovereign Kanban (rendered in the Sharing section).
 *
 * Holds Kanban-specific sharing policy — deliberately independent from the
 * instance-wide Files sharing settings, so an admin can e.g. keep user
 * enumeration off for Files while allowing same-group suggestions in Kanban.
 */
final class AdminSettings implements ISettings {

	/** Recipient-suggestion modes accepted by the sharees endpoint (per type). */
	public const SUGGESTION_MODES = ['exact', 'group', 'all'];
	public const SUGGESTION_MODE_KEY = 'sharee_suggestion_mode';
	public const SUGGESTION_MODE_DEFAULT = 'exact';

	public const SCOPE_MODES = ['member', 'all'];
	public const GROUP_MODE_KEY = 'sharee_group_mode';
	public const TEAM_MODE_KEY = 'sharee_team_mode';
	public const SCOPE_MODE_DEFAULT = 'member';

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript('sovereign-kanban-md-persistence', 'admin');

		return new TemplateResponse('sovereign-kanban-md-persistence', 'admin', [
			'suggestionMode' => $this->appConfig->getValueString(
				'sovereign-kanban-md-persistence',
				self::SUGGESTION_MODE_KEY,
				self::SUGGESTION_MODE_DEFAULT,
			),
			'groupMode' => $this->appConfig->getValueString(
				'sovereign-kanban-md-persistence',
				self::GROUP_MODE_KEY,
				self::SCOPE_MODE_DEFAULT,
			),
			'teamMode' => $this->appConfig->getValueString(
				'sovereign-kanban-md-persistence',
				self::TEAM_MODE_KEY,
				self::SCOPE_MODE_DEFAULT,
			),
		]);
	}

	public function getSection(): string {
		return 'sharing';
	}

	public function getPriority(): int {
		return 80;
	}
}
