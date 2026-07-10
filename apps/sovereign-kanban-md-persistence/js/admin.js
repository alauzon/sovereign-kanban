/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Sovereign Kanban admin panel: persists the recipient-suggestion mode.
 */

(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById('sovereign-kanban-admin');
		if (!root) return;
		const status = document.getElementById('sk-admin-status');

		root.querySelectorAll('input[name="sk-sharee-mode"]').forEach(function (radio) {
			radio.addEventListener('change', async function () {
				status.textContent = '…';
				const res = await fetch(
					OC.generateUrl('/apps/sovereign-kanban-md-persistence/api/v1/admin/settings'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ suggestionMode: radio.value }),
					},
				);
				status.textContent = res.ok
					? t('sovereign-kanban-md-persistence', 'Enregistré.')
					: t('sovereign-kanban-md-persistence', 'Erreur d’enregistrement ({code}).', { code: res.status });
			});
		});
	});
})();
