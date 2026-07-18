<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Main Kanban board shell. The selector, toolbar, forms, columns and card
 * detail panel are rendered by js/main.js from the md-persistence API.
 */

\OCP\Util::addStyle('sovereign-kanban', 'style');

// `?vue=1` opts into the phase-2 Vue shell; the default is the vanilla app. The
// two never load together: one script, one mount point. Until the shell reaches
// parity, a normal page load is unchanged.
$useVue = !empty($_['useVue']);
if ($useVue) {
	\OCP\Util::addScript('sovereign-kanban', 'sovereign-kanban-main');
	// The Vue bundle's CSS (its own + @nextcloud/vue) — one stable file
	// (css/sovereign-kanban-style.css) thanks to cssCodeSplit:false.
	\OCP\Util::addStyle('sovereign-kanban', 'sovereign-kanban-style');
} else {
	\OCP\Util::addScript('sovereign-kanban', 'main');
}
?>
<?php if ($useVue): ?>
<div id="sk-vue"></div>
<?php else: ?>
<div id="sk-app">
	<header class="sk-header">
		<h1>🗂️ Sovereign Kanban</h1>
		<p class="sk-subtitle">Tableau souverain — chaque carte est un fichier <code>.md</code> dans Nextcloud Files</p>
	</header>

	<div class="sk-toolbar">
		<nav id="sk-boards" class="sk-boards" aria-label="Tableaux"></nav>
		<div class="sk-toolbar-actions">
			<button id="sk-new-from-template" class="sk-btn" type="button" hidden>📋 Carte depuis un gabarit</button>
			<button id="sk-new-board" class="sk-btn" type="button">+ Nouveau tableau</button>
			<button id="sk-edit-board" class="sk-btn" type="button" hidden>✎ Éditer</button>
		</div>
	</div>

	<div id="sk-form" class="sk-form" hidden></div>

	<div id="sk-filterbar" class="sk-filterbar" hidden></div>

	<div id="sk-board" class="sk-board">
		<p class="sk-loading">Chargement des tableaux…</p>
	</div>
</div>

<div id="sk-detail" class="sk-detail" hidden></div>
<?php endif; ?>
