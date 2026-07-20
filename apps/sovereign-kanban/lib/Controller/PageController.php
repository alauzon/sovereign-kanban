<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanban\Controller;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the Kanban board page.
 */
final class PageController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct('sovereign-kanban', $request);
	}

	/**
	 * Render the main board.
	 *
	 * NoCSRFRequired + NoAdminRequired let a logged-in user open this page
	 * directly in the browser (a GET page load carries no request token).
	 *
	 * The Vue shell is now the DEFAULT (Alain, 2026-07-19: parity reached, no more
	 * `?vue=1`). `?vue=0` still loads the vanilla app for a side-by-side compare.
	 * This only affects instances where this version is deployed — Entre Tablées
	 * stays on the previous build until Alain's explicit go.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		// Only offer « Importer depuis Deck » where it can actually work: BOTH the
		// import module AND Deck itself must be enabled for this user (Alain,
		// 2026-07-19). On SdP the import app is not installed, so the button would
		// 404 « Import impossible » — hide it. Checking Deck too covers the case
		// where Deck is disabled after the import app was enabled.
		$user = $this->userSession->getUser();
		$importAvailable = $this->appManager->isEnabledForUser('sovereign-kanban-import', $user)
			&& $this->appManager->isEnabledForUser('deck', $user);

		return new TemplateResponse('sovereign-kanban', 'main', [
			'useVue' => $this->request->getParam('vue') !== '0',
			'importAvailable' => $importAvailable,
		]);
	}
}
