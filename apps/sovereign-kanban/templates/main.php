<?php

/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Main Kanban board template.
 *
 * Static columns for now; cards will be loaded from .md files via the
 * md-persistence backend in the next iteration.
 */

\OCP\Util::addStyle('sovereign-kanban', 'style');
\OCP\Util::addScript('sovereign-kanban', 'main');
?>
<div id="sk-app">
	<header class="sk-header">
		<h1>🗂️ Sovereign Kanban</h1>
		<p class="sk-subtitle">Tableau souverain — chaque carte est un fichier <code>.md</code> dans Nextcloud Files</p>
	</header>

	<div class="sk-board">
		<section class="sk-column" data-column="backlog">
			<div class="sk-column-head"><span>Backlog</span><span class="sk-count">2</span></div>
			<div class="sk-cards">
				<article class="sk-card"><h3>Configurer le mail SdP</h3><div class="sk-labels"><span class="sk-label" style="--c:#e85444">infrastructure</span></div></article>
				<article class="sk-card"><h3>Rédiger charte sociocratique</h3><div class="sk-labels"><span class="sk-label" style="--c:#4488ee">gouvernance</span></div></article>
			</div>
		</section>

		<section class="sk-column" data-column="en-cours">
			<div class="sk-column-head"><span>En cours</span><span class="sk-count">1</span></div>
			<div class="sk-cards">
				<article class="sk-card"><h3>Migrer Deck → Sovereign Kanban</h3><div class="sk-labels"><span class="sk-label" style="--c:#e85444">infrastructure</span><span class="sk-label" style="--c:#46ba61">urgent</span></div></article>
			</div>
		</section>

		<section class="sk-column" data-column="termine">
			<div class="sk-column-head"><span>Terminé</span><span class="sk-count">1</span></div>
			<div class="sk-cards">
				<article class="sk-card sk-done"><h3>App Nextcloud qui charge ✓</h3><div class="sk-labels"><span class="sk-label" style="--c:#46ba61">fait</span></div></article>
			</div>
		</section>

		<section class="sk-column" data-column="archive">
			<div class="sk-column-head"><span>Archivé</span><span class="sk-count">0</span></div>
			<div class="sk-cards"></div>
		</section>
	</div>
</div>
