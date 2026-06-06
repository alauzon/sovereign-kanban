<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Main Kanban board shell. The board selector and columns are rendered
 * by js/main.js from live data served by the md-persistence backend API.
 */

\OCP\Util::addStyle('sovereign-kanban', 'style');
\OCP\Util::addScript('sovereign-kanban', 'main');
?>
<div id="sk-app">
	<header class="sk-header">
		<h1>🗂️ Sovereign Kanban</h1>
		<p class="sk-subtitle">Tableau souverain — chaque carte est un fichier <code>.md</code> dans Nextcloud Files</p>
	</header>

	<nav id="sk-boards" class="sk-boards" aria-label="Tableaux"></nav>
	<div id="sk-board" class="sk-board">
		<p class="sk-loading">Chargement des tableaux…</p>
	</div>
</div>
