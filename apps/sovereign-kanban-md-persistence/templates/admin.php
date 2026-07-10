<?php
/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * @var array $_ Template parameters (suggestionMode).
 * @var \OCP\IL10N $l
 */
$mode = $_['suggestionMode'];
?>
<div id="sovereign-kanban-admin" class="section">
	<h2><?php p($l->t('Sovereign Kanban')); ?></h2>
	<h3><?php p($l->t('Suggestion des destinataires de partage')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Contrôle ce que le champ « Usager ou groupe » d’un tableau propose pendant la frappe. Ce réglage est propre au Sovereign Kanban : il est indépendant des réglages de partage de Nextcloud (Files). « Tous » expose la liste des comptes de l’instance aux usagers du Kanban — choisir en connaissance de cause.')); ?>
	</p>
	<p>
		<label>
			<input type="radio" name="sk-sharee-mode" value="exact" class="radio" <?php if ($mode === 'exact') { p('checked'); } ?>>
			<?php p($l->t('Nom exact seulement — aucune suggestion, il faut taper l’identifiant ou le nom complet')); ?>
		</label><br>
		<label>
			<input type="radio" name="sk-sharee-mode" value="group" class="radio" <?php if ($mode === 'group') { p('checked'); } ?>>
			<?php p($l->t('Membres des mêmes groupes — suggère les personnes et groupes que l’usager côtoie déjà')); ?>
		</label><br>
		<label>
			<input type="radio" name="sk-sharee-mode" value="all" class="radio" <?php if ($mode === 'all') { p('checked'); } ?>>
			<?php p($l->t('Tous — suggère parmi tous les comptes et groupes de l’instance')); ?>
		</label>
	</p>
	<p id="sk-admin-status" class="settings-hint" aria-live="polite"></p>
</div>
