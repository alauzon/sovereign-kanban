<?php
/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * @var array $_ Template parameters (suggestionMode, groupMode, teamMode).
 * @var \OCP\IL10N $l
 */
$suggestion = $_['suggestionMode'];
$group = $_['groupMode'];
$team = $_['teamMode'];
?>
<div id="sovereign-kanban-admin" class="section">
	<h2><?php p($l->t('Sovereign Kanban')); ?></h2>
	<h3><?php p($l->t('Suggestion des destinataires de partage')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Contrôle ce que le champ de partage d’un tableau propose pendant la frappe, selon le type sélectionné (personne, groupe, équipe). Ces réglages sont propres au Sovereign Kanban, indépendants des réglages de partage de Nextcloud (Files). Les admins de l’instance reçoivent toujours toutes les suggestions. Le nom exact fonctionne toujours, quel que soit le mode.')); ?>
	</p>

	<h4><?php p($l->t('Personnes')); ?></h4>
	<p>
		<input type="radio" id="sk-mode-exact" name="sk-sharee-mode" value="exact" class="radio" <?php if ($suggestion === 'exact') { p('checked'); } ?>>
		<label for="sk-mode-exact"><?php p($l->t('Nom exact seulement — aucune suggestion')); ?></label><br>
		<input type="radio" id="sk-mode-group" name="sk-sharee-mode" value="group" class="radio" <?php if ($suggestion === 'group') { p('checked'); } ?>>
		<label for="sk-mode-group"><?php p($l->t('Membres des mêmes groupes que l’usager')); ?></label><br>
		<input type="radio" id="sk-mode-all" name="sk-sharee-mode" value="all" class="radio" <?php if ($suggestion === 'all') { p('checked'); } ?>>
		<label for="sk-mode-all"><?php p($l->t('Tous les comptes de l’instance')); ?></label>
	</p>

	<h4><?php p($l->t('Groupes')); ?></h4>
	<p>
		<input type="radio" id="sk-group-member" name="sk-group-mode" value="member" class="radio" <?php if ($group === 'member') { p('checked'); } ?>>
		<label for="sk-group-member"><?php p($l->t('Seulement les groupes dont l’usager est membre')); ?></label><br>
		<input type="radio" id="sk-group-all" name="sk-group-mode" value="all" class="radio" <?php if ($group === 'all') { p('checked'); } ?>>
		<label for="sk-group-all"><?php p($l->t('Tous les groupes de l’instance')); ?></label>
	</p>

	<h4><?php p($l->t('Équipes')); ?></h4>
	<p>
		<input type="radio" id="sk-team-member" name="sk-team-mode" value="member" class="radio" <?php if ($team === 'member') { p('checked'); } ?>>
		<label for="sk-team-member"><?php p($l->t('Seulement les équipes visibles à l’usager')); ?></label><br>
		<input type="radio" id="sk-team-all" name="sk-team-mode" value="all" class="radio" <?php if ($team === 'all') { p('checked'); } ?>>
		<label for="sk-team-all"><?php p($l->t('Toutes — l’app Équipes ne permet pas d’énumération globale : ce mode équivaut pour l’instant aux équipes visibles')); ?></label>
	</p>

	<p id="sk-admin-status" class="settings-hint" aria-live="polite"></p>
</div>
