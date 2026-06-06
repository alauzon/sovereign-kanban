/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Loads boards and their cards from the md-persistence backend and renders
 * them live. Drag-drop and card editing come next.
 */

(function () {
	'use strict';

	const BOARDS_PATH = '/apps/sovereign-kanban-md-persistence/api/v1/boards';

	function boardsUrl() {
		return (window.OC && OC.generateUrl) ? OC.generateUrl(BOARDS_PATH) : BOARDS_PATH;
	}

	function cardsUrl(boardId) {
		return boardsUrl() + '/' + encodeURIComponent(boardId) + '/cards';
	}

	function el(tag, className, text) {
		const node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined) node.textContent = text;
		return node;
	}

	function renderColumns(board, cardsByColumn) {
		const boardEl = document.getElementById('sk-board');
		boardEl.innerHTML = '';
		(board.columns || []).forEach(function (name) {
			const cards = (cardsByColumn && cardsByColumn[name]) || [];
			const col = el('section', 'sk-column');
			const head = el('div', 'sk-column-head');
			head.appendChild(el('span', null, name));
			head.appendChild(el('span', 'sk-count', String(cards.length)));
			col.appendChild(head);

			const cardsEl = el('div', 'sk-cards');
			cards.forEach(function (card) {
				const art = el('article', 'sk-card');
				art.appendChild(el('h3', null, card.title));
				cardsEl.appendChild(art);
			});
			col.appendChild(cardsEl);
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
		const boardEl = document.getElementById('sk-board');
		boardEl.innerHTML = '';
		boardEl.appendChild(el('p', 'sk-loading', msg));
	}

	async function loadCards(board) {
		let cardsByColumn = {};
		try {
			const res = await fetch(cardsUrl(board.id), { headers: { 'OCS-APIRequest': 'true' } });
			if (res.ok) {
				const data = await res.json();
				cardsByColumn = data.cards || {};
			}
		} catch (e) {
			// Keep the empty columns already shown.
		}
		renderColumns(board, cardsByColumn);
	}

	async function load() {
		try {
			const res = await fetch(boardsUrl(), { headers: { 'OCS-APIRequest': 'true' } });
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
			const select = function (board) {
				renderSelector(boards, board.id, select);
				renderColumns(board, {});
				loadCards(board);
			};
			select(boards[0]);
		} catch (e) {
			showMessage('Impossible de joindre l’API : ' + e.message);
		}
	}

	document.addEventListener('DOMContentLoaded', load);
})();
