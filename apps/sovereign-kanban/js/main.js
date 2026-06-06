/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Sovereign Kanban frontend: boards (list/create/edit) + cards (list/create/
 * edit). Comments, column management and drag-drop come next.
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
	function cardsUrl(boardId) { return boardUrl(boardId) + '/cards'; }
	function cardUrl(boardId, cardId) { return cardsUrl(boardId) + '/' + encodeURIComponent(cardId); }
	function commentsUrl(boardId, cardId) { return cardUrl(boardId, cardId) + '/comments'; }
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

	function currentBoard() {
		return boards.find(function (b) { return b.id === currentId; }) || null;
	}

	/* ---- Columns + cards ---- */

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
				art.addEventListener('click', function () { openCard(card.id); });
				cardsEl.appendChild(art);
			});
			col.appendChild(cardsEl);

			const add = el('button', 'sk-add-card', '+ Carte');
			add.addEventListener('click', function () { showAddCard(col, name); });
			col.appendChild(add);

			boardEl.appendChild(col);
		});
	}

	function showAddCard(colEl, columnName) {
		if (colEl.querySelector('.sk-add-form')) {
			colEl.querySelector('.sk-add-form input').focus();
			return;
		}
		const form = el('div', 'sk-add-form');
		const input = el('input', 'sk-input');
		input.type = 'text';
		input.placeholder = 'Titre de la carte';
		const submit = el('button', 'sk-btn sk-btn-primary', 'Ajouter');

		const doAdd = async function () {
			const title = input.value.trim();
			if (!title) { input.focus(); return; }
			submit.disabled = true;
			const res = await api('POST', cardsUrl(currentId), { title: title, column: columnName });
			if (res.ok) {
				loadCards(currentBoard());
			} else {
				submit.disabled = false;
				window.alert('Erreur ' + res.status);
			}
		};
		submit.addEventListener('click', doAdd);
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') doAdd(); });

		form.appendChild(input);
		form.appendChild(submit);
		colEl.appendChild(form);
		input.focus();
	}

	async function loadCards(board) {
		if (!board) return;
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

	/* ---- Card detail / edit ---- */

	async function openCard(cardId) {
		const res = await api('GET', cardUrl(currentId, cardId));
		if (!res.ok) { window.alert('Erreur ' + res.status); return; }
		const data = await res.json();
		renderDetail(data.card);
	}

	function renderDetail(card) {
		const panel = document.getElementById('sk-detail');
		panel.hidden = false;
		panel.innerHTML = '';

		const backdrop = el('div', 'sk-detail-backdrop');
		backdrop.addEventListener('click', function () { panel.hidden = true; });

		const box = el('div', 'sk-detail-box');
		const close = el('button', 'sk-detail-close', '✕');
		close.addEventListener('click', function () { panel.hidden = true; });

		const titleInput = el('input', 'sk-input sk-detail-title');
		titleInput.type = 'text';
		titleInput.value = card.title;

		const bodyArea = el('textarea', 'sk-detail-body');
		bodyArea.value = card.description || '';
		bodyArea.placeholder = 'Description (Markdown)…';

		const save = el('button', 'sk-btn sk-btn-primary', 'Enregistrer');
		save.addEventListener('click', async function () {
			save.disabled = true;
			const res = await api('PUT', cardUrl(currentId, card.id), {
				title: titleInput.value.trim(),
				description: bodyArea.value,
			});
			if (res.ok) {
				panel.hidden = true;
				loadCards(currentBoard());
			} else {
				save.disabled = false;
				window.alert('Erreur ' + res.status);
			}
		});

		const comments = el('div', 'sk-comments');
		comments.appendChild(el('h4', 'sk-comments-title', 'Commentaires'));
		const list = el('div', 'sk-comments-list');
		comments.appendChild(list);
		const commentInput = el('textarea', 'sk-comment-input');
		commentInput.placeholder = 'Ajouter un commentaire…';
		const commentAdd = el('button', 'sk-btn sk-btn-primary', 'Commenter');
		commentAdd.addEventListener('click', async function () {
			const body = commentInput.value.trim();
			if (!body) { commentInput.focus(); return; }
			commentAdd.disabled = true;
			const res = await api('POST', commentsUrl(currentId, card.id), { body: body });
			commentAdd.disabled = false;
			if (res.ok) { commentInput.value = ''; loadComments(card.id, list); }
			else { window.alert('Erreur ' + res.status); }
		});
		comments.appendChild(commentInput);
		comments.appendChild(commentAdd);

		box.appendChild(close);
		box.appendChild(titleInput);
		box.appendChild(bodyArea);
		box.appendChild(save);
		box.appendChild(comments);
		panel.appendChild(backdrop);
		panel.appendChild(box);
		titleInput.focus();
		loadComments(card.id, list);
	}

	async function loadComments(cardId, listEl) {
		listEl.innerHTML = '';
		let comments = [];
		try {
			const res = await api('GET', commentsUrl(currentId, cardId));
			if (res.ok) { const data = await res.json(); comments = data.comments || []; }
		} catch (e) { /* none */ }
		if (comments.length === 0) {
			listEl.appendChild(el('p', 'sk-loading', 'Aucun commentaire.'));
			return;
		}
		comments.forEach(function (c) {
			const item = el('div', 'sk-comment');
			item.appendChild(el('div', 'sk-comment-meta', c.author + ' — ' + formatDate(c.created_at)));
			item.appendChild(el('div', 'sk-comment-body', c.body));
			listEl.appendChild(item);
		});
	}

	function formatDate(iso) {
		try { return new Date(iso).toLocaleString('fr-CA'); } catch (e) { return iso; }
	}

	/* ---- Board selector + create/edit ---- */

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
		const board = currentBoard();
		if (board) {
			renderColumns(board, {});
			loadCards(board);
		}
	}

	function showBoardForm(mode, board) {
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
			showBoardForm('create', null);
		});
		document.getElementById('sk-edit-board').addEventListener('click', function () {
			const board = currentBoard();
			if (board) showBoardForm('edit', board);
		});
		reload(null).catch(function (e) {
			showMessage('Impossible de joindre l’API : ' + e.message);
		});
	}

	document.addEventListener('DOMContentLoaded', init);
})();
