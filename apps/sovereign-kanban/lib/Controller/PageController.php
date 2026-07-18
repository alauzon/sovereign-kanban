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
	 * `?vue=1` opts into the Vue shell (phase 2, under construction) instead of
	 * the vanilla app. The default stays vanilla so the transition is not a
	 * big-bang cutover: the shell reaches parity behind this flag, then becomes
	 * the default. Nothing changes for a normal page load until then.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return new TemplateResponse('sovereign-kanban', 'main', [
			'useVue' => $this->request->getParam('vue') === '1',
		]);
	}
}
