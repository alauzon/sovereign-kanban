<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanban\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Serves the Kanban board page.
 */
final class PageController extends Controller {

	public function __construct(IRequest $request) {
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
		return new TemplateResponse('sovereign-kanban', 'main', [
			'useVue' => $this->request->getParam('vue') !== '0',
		]);
	}
}
