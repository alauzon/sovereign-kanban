/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Loads boards from the md-persistence backend and renders them live.
 * Cards are not fetched yet (a cards endpoint comes next); columns render
 * empty for now.
 */

(function () {
	'use strict';

	const API_PATH = '/apps/sovereign-kanban-md-persistence/api/v1/boards';

	function apiUrl() {
		return (window.OC && OC.generateUrl) ? OC.generateUrl(API_PATH) : API_PATH;
	}

	function el(tag, className, text) {
		const node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined) node.textContent = text;
		return node;
	}

	function renderColumns(board) {
		const boardEl = document.getElementById('sk-board');
		boardEl.innerHTML = '';
		(board.columns || []).forEach(function (name) {
			const col = el('section', 'sk-column');
			const head = el('div', 'sk-column-head');
			head.appendChild(el('span', null, name));
			head.appendChild(el('span', 'sk-count', '0'));
			col.appendChild(head);
			col.appendChild(el('div', 'sk-cards'));
			boardEl.appendChild(col);
		});
	}

	function renderSelector(boards, current, onSelect) {
		const nav = document.getElementById('sk-boards');
		nav.innerHTML = '';
		boards.forEach(function (board) {
			const btn = el('button', 'sk-board-tab' + (board.id === current ? ' is-active' : ''));
			const dot = el('span', 'sk-board-dot');
			dot.style.background = board.color || '#888';
			btn.appendChild(dot);
			btn.appendChild(el('span', null, board.name));
			btn.addEventListener('click', function () { onSelect(board); });
			nav.appendChild(btn);
		});
	}

	function showMessage(msg) {
		document.getElementById('sk-board').innerHTML = '';
		document.getElementById('sk-board').appendChild(el('p', 'sk-loading', msg));
	}

	async function load() {
		try {
			const res = await fetch(apiUrl(), { headers: { 'OCS-APIRequest': 'true' } });
			if (!res.ok) {
				showMessage('Erreur ' + res.status + ' au chargement des tableaux.');
				return;
			}
			const data = await res.json();
			const boards = data.boards || [];
			if (boards.length === 0) {
				document.getElementById('sk-boards').innerHTML = '';
				showMessage('Aucun tableau. Crée un dossier sous Files/Kanban/ pour commencer.');
				return;
			}
			let current = boards[0];
			const select = function (board) {
				current = board;
				renderSelector(boards, current.id, select);
				renderColumns(current);
			};
			select(current);
		} catch (e) {
			showMessage('Impossible de joindre l’API : ' + e.message);
		}
	}

	document.addEventListener('DOMContentLoaded', load);
})();
