/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Sovereign Kanban admin panel: persists the recipient-suggestion policy.
 */

(function () {
	'use strict';

	const PARAM_BY_NAME = {
		'sk-sharee-mode': 'suggestionMode',
		'sk-group-mode': 'groupMode',
		'sk-team-mode': 'teamMode',
	};

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById('sovereign-kanban-admin');
		if (!root) return;
		const status = document.getElementById('sk-admin-status');

		root.querySelectorAll('input[type="radio"]').forEach(function (radio) {
			radio.addEventListener('change', async function () {
				const param = PARAM_BY_NAME[radio.name];
				if (!param) return;
				status.textContent = '…';
				const body = {};
				body[param] = radio.value;
				const res = await fetch(
					OC.generateUrl('/apps/sovereign-kanban-md-persistence/api/v1/admin/settings'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(body),
					},
				);
				status.textContent = res.ok
					? t('sovereign-kanban-md-persistence', 'Enregistré.')
					: t('sovereign-kanban-md-persistence', 'Erreur d’enregistrement ({code}).', { code: res.status });
			});
		});
	});
})();
