<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 */

namespace OCA\SovereignKanbanMdPersistence\Controller;

use OCA\SovereignKanbanMdPersistence\Kanban\TemplateLibrary;
use OCA\SovereignKanbanMdPersistence\Storage\NextcloudStorage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only API over the card templates ("Modèles") and process snippets
 * ("Procédures") that live as plain .md files in Files/Kanban/. The library
 * seeds sensible defaults on first use; everything stays editable in Files.
 */
final class TemplateController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IRootFolder $rootFolder,
	) {
		parent::__construct('sovereign-kanban-md-persistence', $request);
	}

	/**
	 * List card templates (frontmatter + body, frontmatter stripped from body).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): DataResponse {
		$library = $this->library();
		if ($library === null) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		return new DataResponse(['templates' => $library->templates()]);
	}

	/**
	 * List process snippets to insert into a card.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function procedures(): DataResponse {
		$library = $this->library();
		if ($library === null) {
			return new DataResponse(['error' => 'unavailable'], 400);
		}

		return new DataResponse(['procedures' => $library->procedures()]);
	}

	/**
	 * Build the library rooted at Files/Kanban/ (created if missing).
	 */
	private function library(): ?TemplateLibrary {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$kanban = $userFolder->nodeExists('Kanban')
			? $userFolder->get('Kanban')
			: $userFolder->newFolder('Kanban');
		if (!$kanban instanceof Folder) {
			return null;
		}

		return new TemplateLibrary(new NextcloudStorage($kanban));
	}
}
