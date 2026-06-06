/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Loads boards and their cards from the md-persistence backend, renders them
 * live, and lets the user create / edit boards. Cards CRUD and drag-drop come
 * next.
 */

(function () {
	'use strict';

	const BOARDS_PATH = '/apps/sovereign-kanban-md-persistence/api/v1/boards';

	let boards = [];
	let currentId = null;

	function boardsUrl() {
		return (window.OC && OC.generateUrl) ? OC.generateUrl(BOARDS_PATH) : BOARDS_PATH;
	}
	function boardUrl(id) { return boardsUrl() + '/' + encodeURIComponent(id); }
	function cardsUrl(id) { return boardUrl(id) + '/cards'; }
	function token() { return (window.OC && OC.requestToken) ? OC.requestToken : ''; }

	function el(tag, className, text) {
		const node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined) node.textContent = text;
		return node;
	}

	async function api(method, url, body) {
		const opts = { method: method, headers: { 'OCS-APIRequest': 'true', requesttoken: token() } };
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(url, opts);
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

	async function loadCards(board) {
		let cardsByColumn = {};
		try {
			const res = await api('GET', cardsUrl(board.id));
			if (res.ok) {
				const data = await res.json();
				cardsByColumn = data.cards || {};
			}
		} catch (e) { /* keep empty columns */ }
		renderColumns(board, cardsByColumn);
	}

	function renderSelector() {
		const nav = document.getElementById('sk-boards');
		nav.innerHTML = '';
		boards.forEach(function (board) {
			const btn = el('button', 'sk-board-tab' + (board.id === currentId ? ' is-active' : ''));
			const dot = el('span', 'sk-board-dot');
			dot.style.background = board.color || '#888';
			btn.appendChild(dot);
			btn.appendChild(el('span', null, board.name));
			btn.addEventListener('click', function () { select(board.id); });
			nav.appendChild(btn);
		});
		document.getElementById('sk-edit-board').hidden = (currentId === null);
	}

	function select(id) {
		currentId = id;
		renderSelector();
		const board = boards.find(function (b) { return b.id === id; });
		if (board) {
			renderColumns(board, {});
			loadCards(board);
		}
	}

	function showForm(mode, board) {
		const form = document.getElementById('sk-form');
		form.hidden = false;
		form.innerHTML = '';

		const nameInput = el('input', 'sk-input');
		nameInput.type = 'text';
		nameInput.placeholder = 'Nom du tableau';
		nameInput.value = board ? board.name : '';

		const colorInput = el('input', 'sk-color');
		colorInput.type = 'color';
		colorInput.value = board ? board.color : '#0082c9';

		const submit = el('button', 'sk-btn sk-btn-primary', mode === 'create' ? 'Créer' : 'Enregistrer');
		const cancel = el('button', 'sk-btn', 'Annuler');

		submit.addEventListener('click', async function () {
			const name = nameInput.value.trim();
			if (!name) { nameInput.focus(); return; }
			submit.disabled = true;
			const payload = { name: name, color: colorInput.value };
			const res = (mode === 'create')
				? await api('POST', boardsUrl(), payload)
				: await api('PUT', boardUrl(board.id), payload);
			if (res.ok) {
				const data = await res.json();
				form.hidden = true;
				await reload(data.board ? data.board.id : currentId);
			} else {
				submit.disabled = false;
				window.alert('Erreur ' + res.status);
			}
		});
		cancel.addEventListener('click', function () { form.hidden = true; });

		form.appendChild(nameInput);
		form.appendChild(colorInput);
		form.appendChild(submit);
		form.appendChild(cancel);
		nameInput.focus();
	}

	function showMessage(msg) {
		const boardEl = document.getElementById('sk-board');
		boardEl.innerHTML = '';
		boardEl.appendChild(el('p', 'sk-loading', msg));
	}

	async function reload(selectId) {
		const res = await api('GET', boardsUrl());
		const data = await res.json();
		boards = data.boards || [];
		if (boards.length === 0) {
			currentId = null;
			renderSelector();
			showMessage('Aucun tableau. Crée ton premier avec « + Nouveau tableau ».');
			return;
		}
		const exists = boards.some(function (b) { return b.id === selectId; });
		select(exists ? selectId : boards[0].id);
	}

	function init() {
		document.getElementById('sk-new-board').addEventListener('click', function () {
			showForm('create', null);
		});
		document.getElementById('sk-edit-board').addEventListener('click', function () {
			const board = boards.find(function (b) { return b.id === currentId; });
			if (board) showForm('edit', board);
		});
		reload(null).catch(function (e) {
			showMessage('Impossible de joindre l’API : ' + e.message);
		});
	}

	document.addEventListener('DOMContentLoaded', init);
})();
