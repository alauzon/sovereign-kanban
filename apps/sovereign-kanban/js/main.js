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
	function commentUrl(boardId, cardId, commentId) { return commentsUrl(boardId, cardId) + '/' + encodeURIComponent(commentId); }
	function apiUrl(path) { return (window.OC && OC.generateUrl) ? OC.generateUrl(path) : path; }
	function templatesUrl() { return apiUrl('/apps/sovereign-kanban-md-persistence/api/v1/templates'); }
	function proceduresUrl() { return apiUrl('/apps/sovereign-kanban-md-persistence/api/v1/procedures'); }
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
			col.dataset.column = name;
			col.addEventListener('dragover', function (e) { e.preventDefault(); col.classList.add('sk-dragover'); });
			col.addEventListener('dragleave', function () { col.classList.remove('sk-dragover'); });
			col.addEventListener('drop', function (e) {
				e.preventDefault();
				col.classList.remove('sk-dragover');
				const cardId = e.dataTransfer.getData('text/plain');
				if (cardId) moveCardTo(cardId, name);
			});

			const head = el('div', 'sk-column-head');
			head.appendChild(el('span', 'sk-column-name', name));
			const colActions = el('span', 'sk-column-actions');
			const colBtn = function (label, title, fn) {
				const b = el('button', 'sk-col-btn', label);
				b.title = title;
				b.addEventListener('click', fn);
				return b;
			};
			colActions.appendChild(colBtn('◀', 'Déplacer à gauche', function () { moveColumn(name, -1); }));
			colActions.appendChild(colBtn('▶', 'Déplacer à droite', function () { moveColumn(name, 1); }));
			colActions.appendChild(colBtn('✎', 'Renommer', function () { renameColumnPrompt(name); }));
			colActions.appendChild(colBtn('✕', 'Supprimer la colonne', function () { removeColumnConfirm(name); }));
			head.appendChild(colActions);
			head.appendChild(el('span', 'sk-count', String(cards.length)));
			col.appendChild(head);

			const cardsEl = el('div', 'sk-cards');
			cards.forEach(function (card) {
				const art = el('article', 'sk-card');
				art.draggable = true;
				art.addEventListener('dragstart', function (e) {
					e.dataTransfer.setData('text/plain', card.id);
					e.dataTransfer.effectAllowed = 'move';
					art.classList.add('sk-dragging');
				});
				art.addEventListener('dragend', function () { art.classList.remove('sk-dragging'); });
				art.appendChild(el('h3', null, card.title));
				if (card.excerpt) {
					art.appendChild(el('p', 'sk-card-excerpt', card.excerpt));
				}
				const assignees = card.assignees || [];
				if (card.due_date || assignees.length) {
					const meta = el('div', 'sk-card-meta');
					if (card.due_date) {
						meta.appendChild(el('span', 'sk-due', '📅 ' + card.due_date));
					}
					assignees.forEach(function (a) {
						meta.appendChild(el('span', 'sk-assignee', a));
					});
					art.appendChild(meta);
				}
				art.addEventListener('click', function () { openCard(card.id); });
				cardsEl.appendChild(art);
			});
			col.appendChild(cardsEl);

			const add = el('button', 'sk-add-card', '+ Carte');
			add.addEventListener('click', function () { showAddCard(col, name); });
			col.appendChild(add);

			boardEl.appendChild(col);
		});

		const addColumn = el('button', 'sk-add-column', '+ Colonne');
		addColumn.addEventListener('click', addColumnPrompt);
		boardEl.appendChild(addColumn);
	}

	function columnsUrl() { return boardUrl(currentId) + '/columns'; }

	async function addColumnPrompt() {
		const name = window.prompt('Nom de la nouvelle colonne');
		if (!name || !name.trim()) { return; }
		const res = await api('POST', columnsUrl(), { name: name.trim() });
		if (res.ok) { reload(currentId); } else { window.alert('Erreur ' + res.status); }
	}

	async function renameColumnPrompt(from) {
		const to = window.prompt('Renommer la colonne', from);
		if (!to || !to.trim() || to.trim() === from) { return; }
		const res = await api('PUT', columnsUrl() + '/rename', { from: from, to: to.trim() });
		if (res.ok) { reload(currentId); } else { window.alert('Erreur ' + res.status); }
	}

	async function removeColumnConfirm(name) {
		if (!window.confirm('Supprimer la colonne « ' + name + ' » et ses cartes ?')) { return; }
		const res = await api('DELETE', columnsUrl(), { name: name });
		if (res.ok) { reload(currentId); } else { window.alert('Erreur ' + res.status); }
	}

	async function moveColumn(name, dir) {
		const cols = (currentBoard().columns || []).slice();
		const i = cols.indexOf(name);
		const j = i + dir;
		if (i < 0 || j < 0 || j >= cols.length) { return; }
		cols[i] = cols[j];
		cols[j] = name;
		const res = await api('PUT', columnsUrl() + '/reorder', { columns: cols });
		if (res.ok) { reload(currentId); } else { window.alert('Erreur ' + res.status); }
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
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { doAdd(); }
			else if (e.key === 'Escape') { e.preventDefault(); form.remove(); }
		});

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

	async function moveCardTo(cardId, toColumn) {
		const res = await api('PUT', cardUrl(currentId, cardId) + '/move', { toColumn: toColumn });
		if (!res.ok) { window.alert('Erreur ' + res.status); }
		loadCards(currentBoard());
	}

	/* ---- Card detail / edit ---- */

	async function openCard(cardId) {
		const res = await api('GET', cardUrl(currentId, cardId));
		if (!res.ok) { window.alert('Erreur ' + res.status); return; }
		const data = await res.json();
		renderDetail(data.card);
	}

	let textEditorPromise = null;

	function loadTextEditor() {
		if (textEditorPromise) { return textEditorPromise; }
		const base = (window.OC && OC.getRootPath) ? OC.getRootPath() : '';
		// ?skv bust le cache navigateur : le .mjs avait été mis en cache 6 mois
		// avec un mauvais MIME (octet-stream) avant le fix nginx.
		const url = base + '/apps/text/js/text-editor.mjs?skv=2';
		textEditorPromise = import(/* webpackIgnore: true */ url)
			.then(function (mod) {
				if (mod && typeof mod.createEditor === 'function') { return mod.createEditor; }
				if (window.OCA && window.OCA.Text && window.OCA.Text.createEditor) { return window.OCA.Text.createEditor; }
				return null;
			})
			.catch(function () { return null; });
		return textEditorPromise;
	}

	function renderDetail(card) {
		const panel = document.getElementById('sk-detail');
		// Lift the overlay out of #content to document.body, otherwise the NC
		// header sits in a higher stacking context and intercepts clicks on our
		// fullscreen ✕/⛶ buttons. At body level our z-index wins.
		if (panel.parentNode !== document.body) { document.body.appendChild(panel); }
		panel.hidden = false;
		panel.innerHTML = '';

		let editorInstance = null;
		let descriptionMarkdown = card.description || '';
		let dirty = false;
		let editorMounted = false;
		let onKey = null;

		const closePanel = function () {
			if (onKey) { document.removeEventListener('keydown', onKey, true); onKey = null; }
			if (editorInstance && typeof editorInstance.destroy === 'function') {
				try { editorInstance.destroy(); } catch (e) { /* ignore */ }
			}
			panel.hidden = true;
		};

		// Three-way guard against losing unsaved edits (Maggie, UX).
		// Resolves 'save' | 'discard' | 'cancel'.
		const confirmUnsaved = function () {
			return new Promise(function (resolve) {
				const overlay = el('div', 'sk-confirm');
				const cbox = el('div', 'sk-confirm-box');
				cbox.appendChild(el('p', 'sk-confirm-msg', 'Des modifications ne sont pas enregistrées.'));
				const row = el('div', 'sk-confirm-actions');
				const bSave = el('button', 'sk-btn sk-btn-primary', 'Enregistrer');
				const bDiscard = el('button', 'sk-btn sk-btn-danger', 'Abandonner');
				const bCancel = el('button', 'sk-btn', 'Continuer l’édition');
				let ck = null;
				const done = function (choice) {
					if (ck) { document.removeEventListener('keydown', ck, true); ck = null; }
					overlay.remove();
					resolve(choice);
				};
				// Esc inside the dialog = the safe option (keep editing).
				ck = function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done('cancel'); } };
				document.addEventListener('keydown', ck, true);
				bSave.addEventListener('click', function () { done('save'); });
				bDiscard.addEventListener('click', function () { done('discard'); });
				bCancel.addEventListener('click', function () { done('cancel'); });
				row.appendChild(bSave);
				row.appendChild(bDiscard);
				row.appendChild(bCancel);
				cbox.appendChild(row);
				overlay.appendChild(cbox);
				panel.appendChild(overlay);
				bCancel.focus();
			});
		};

		// Close request that honours unsaved changes.
		let confirming = false;
		const requestClose = async function () {
			if (!dirty) { closePanel(); return; }
			if (confirming) { return; }
			confirming = true;
			const choice = await confirmUnsaved();
			confirming = false;
			if (choice === 'cancel') { return; }
			if (choice === 'discard') { closePanel(); return; }
			if (choice === 'save' && await saveCard()) {
				closePanel();
				loadCards(currentBoard());
			}
		};

		// Esc = same path as ✕ (a fallback if the button is ever unreachable).
		// Capture phase: the Text/ProseMirror editor swallows Escape on bubble,
		// so we must intercept it before the editor does.
		onKey = function (e) {
			if (e.key !== 'Escape') { return; }
			e.preventDefault();
			e.stopPropagation();
			// While the unsaved-changes dialog is open, it handles Esc itself.
			if (!confirming) { requestClose(); }
		};
		document.addEventListener('keydown', onKey, true);

		const backdrop = el('div', 'sk-detail-backdrop');
		// A backdrop click is a weak signal — it must never destroy work.
		// With unsaved edits, ignore it; close only via ✕, Esc, or the buttons.
		backdrop.addEventListener('click', function () { if (!dirty) { closePanel(); } });

		const box = el('div', 'sk-detail-box');
		const close = el('button', 'sk-detail-close', '✕');
		close.addEventListener('click', function () { requestClose(); });

		const fsBtn = el('button', 'sk-detail-fs', '⛶');
		fsBtn.title = 'Plein écran';
		fsBtn.addEventListener('click', function () {
			const on = box.classList.toggle('sk-fullscreen');
			fsBtn.classList.toggle('is-on', on);
			fsBtn.title = on ? 'Quitter le plein écran' : 'Plein écran';
		});

		// Resizable width — grab the left edge to widen/narrow. Persisted.
		let detailWidth = parseInt(window.localStorage.getItem('sk-detail-width') || '', 10) || 0;
		if (detailWidth) {
			box.style.width = Math.min(detailWidth, Math.round(window.innerWidth * 0.96)) + 'px';
		}
		const resizer = el('div', 'sk-detail-resize');
		resizer.title = 'Glisser pour redimensionner';
		resizer.addEventListener('mousedown', function (e) {
			if (box.classList.contains('sk-fullscreen')) { return; }
			e.preventDefault();
			const startX = e.clientX;
			const startW = box.offsetWidth;
			document.body.style.userSelect = 'none';
			const move = function (ev) {
				// The box is centred → moving the left edge by Δ changes width by 2Δ.
				let w = startW + (startX - ev.clientX) * 2;
				w = Math.max(360, Math.min(w, Math.round(window.innerWidth * 0.96)));
				box.style.width = w + 'px';
				detailWidth = w;
			};
			const up = function () {
				document.removeEventListener('mousemove', move);
				document.removeEventListener('mouseup', up);
				document.body.style.userSelect = '';
				try { window.localStorage.setItem('sk-detail-width', String(detailWidth)); } catch (e2) { /* ignore */ }
			};
			document.addEventListener('mousemove', move);
			document.addEventListener('mouseup', up);
		});

		const markDirty = function () { dirty = true; };

		const titleInput = el('input', 'sk-input sk-detail-title');
		titleInput.type = 'text';
		titleInput.value = card.title;
		titleInput.addEventListener('input', markDirty);

		const dueInput = el('input', 'sk-input');
		dueInput.type = 'date';
		dueInput.value = card.due_date || '';
		dueInput.addEventListener('change', markDirty);
		const dueRow = el('label', 'sk-field');
		dueRow.appendChild(el('span', 'sk-field-label', 'Date limite'));
		dueRow.appendChild(dueInput);

		const assigneesInput = el('input', 'sk-input');
		assigneesInput.type = 'text';
		assigneesInput.placeholder = 'alain, steve';
		assigneesInput.value = (card.assignees || []).join(', ');
		assigneesInput.addEventListener('input', markDirty);
		const assigneesRow = el('label', 'sk-field');
		assigneesRow.appendChild(el('span', 'sk-field-label', 'Assignés (séparés par des virgules)'));
		assigneesRow.appendChild(assigneesInput);

		// Description: the Nextcloud Text editor (same as Deck), content mode,
		// with a plain textarea fallback if the Text module can't be loaded.
		const editorEl = el('div', 'sk-detail-editor');
		const fallback = el('textarea', 'sk-detail-body');
		fallback.placeholder = 'Description (Markdown)…';
		fallback.value = descriptionMarkdown;
		fallback.hidden = true;
		fallback.addEventListener('input', function () { descriptionMarkdown = fallback.value; dirty = true; });
		loadTextEditor().then(function (createEditor) {
			if (!createEditor) {
				editorEl.hidden = true;
				fallback.hidden = false;
				editorMounted = true;
				return;
			}
			try {
				const result = createEditor({
					el: editorEl,
					content: descriptionMarkdown,
					useSession: false,
					autofocus: false,
					onUpdate: function (data) {
						descriptionMarkdown = data.markdown;
						if (editorMounted) { dirty = true; }
					},
				});
				Promise.resolve(result).then(function (instance) {
					editorInstance = instance;
					// Defer past any initial onUpdate fired during mount, so loading
					// the existing content doesn't count as an unsaved change.
					setTimeout(function () { editorMounted = true; }, 0);
				}).catch(function () {
					editorEl.hidden = true;
					fallback.hidden = false;
					editorMounted = true;
				});
			} catch (e) {
				editorEl.hidden = true;
				fallback.hidden = false;
				editorMounted = true;
			}
		});

		async function saveCard() {
			const res = await api('PUT', cardUrl(currentId, card.id), {
				title: titleInput.value.trim(),
				description: descriptionMarkdown,
				due_date: dueInput.value,
				assignees: assigneesInput.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean),
			});
			if (res.ok) { dirty = false; return true; }
			window.alert('Erreur ' + res.status);
			return false;
		}

		const save = el('button', 'sk-btn sk-btn-primary', 'Enregistrer');
		save.addEventListener('click', async function () {
			save.disabled = true;
			if (await saveCard()) {
				closePanel();
				loadCards(currentBoard());
			} else {
				save.disabled = false;
			}
		});

		const comments = el('div', 'sk-comments');
		comments.appendChild(el('h4', 'sk-comments-title', 'Commentaires'));
		const list = el('div', 'sk-comments-list');
		// Add box on top, newest comments first (loadComments reverses).
		comments.appendChild(renderAddComment(card.id, list));
		comments.appendChild(list);

		const del = el('button', 'sk-btn sk-btn-danger', 'Supprimer');
		del.addEventListener('click', async function () {
			if (!window.confirm('Supprimer cette carte ?')) { return; }
			del.disabled = true;
			const res = await api('DELETE', cardUrl(currentId, card.id));
			if (res.ok) { closePanel(); loadCards(currentBoard()); }
			else { del.disabled = false; window.alert('Erreur ' + res.status); }
		});
		const actions = el('div', 'sk-detail-actions');
		actions.appendChild(del);
		actions.appendChild(save);

		box.appendChild(close);
		box.appendChild(fsBtn);
		box.appendChild(resizer);
		box.appendChild(titleInput);
		box.appendChild(dueRow);
		box.appendChild(assigneesRow);
		box.appendChild(el('span', 'sk-field-label', 'Description'));
		box.appendChild(editorEl);
		box.appendChild(fallback);
		box.appendChild(actions);
		box.appendChild(comments);
		panel.appendChild(backdrop);
		panel.appendChild(box);
		titleInput.focus();
		loadComments(card.id, list);
	}

	/**
	 * The "add a comment" box, shown above the list. Collapsed to a placeholder;
	 * a click expands the Text editor (Markdown) with Commenter / Annuler.
	 */
	function renderAddComment(cardId, listEl) {
		const wrap = el('div', 'sk-comment-add');
		const placeholder = el('div', 'sk-comment-addph', 'Ajouter un commentaire…');
		placeholder.addEventListener('click', function () {
			if (wrap.querySelector('.sk-comment-editwrap')) { return; }
			placeholder.hidden = true;

			let md = '';
			let editor = null;
			const editwrap = el('div', 'sk-comment-editwrap');
			const editorEl = el('div', 'sk-comment-editor');
			const fallback = el('textarea', 'sk-comment-input');
			fallback.placeholder = 'Commentaire (Markdown)…';
			fallback.hidden = true;
			fallback.addEventListener('input', function () { md = fallback.value; });

			loadTextEditor().then(function (createEditor) {
				if (!createEditor) { editorEl.hidden = true; fallback.hidden = false; return; }
				try {
					const result = createEditor({
						el: editorEl, content: '', useSession: false, autofocus: true,
						onUpdate: function (data) { md = data.markdown; },
					});
					Promise.resolve(result).then(function (inst) { editor = inst; })
						.catch(function () { editorEl.hidden = true; fallback.hidden = false; });
				} catch (e) { editorEl.hidden = true; fallback.hidden = false; }
			});

			const cleanup = function () {
				if (editor && typeof editor.destroy === 'function') { try { editor.destroy(); } catch (e) { /* ignore */ } }
			};
			const reset = function () { cleanup(); editwrap.remove(); placeholder.hidden = false; };

			const add = el('button', 'sk-btn sk-btn-primary', 'Commenter');
			add.addEventListener('click', async function () {
				const body = (md || '').trim();
				if (!body) { return; }
				add.disabled = true;
				const res = await api('POST', commentsUrl(currentId, cardId), { body: body });
				if (res.ok) { reset(); loadComments(cardId, listEl); }
				else { add.disabled = false; window.alert('Erreur ' + res.status); }
			});
			const cancel = el('button', 'sk-btn', 'Annuler');
			cancel.addEventListener('click', reset);

			const actions = el('div', 'sk-comment-editactions');
			actions.appendChild(add);
			actions.appendChild(cancel);
			editwrap.appendChild(editorEl);
			editwrap.appendChild(fallback);
			editwrap.appendChild(actions);
			wrap.appendChild(editwrap);
		});
		wrap.appendChild(placeholder);
		return wrap;
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
		// Newest first.
		comments.slice().reverse().forEach(function (c) {
			listEl.appendChild(renderComment(c, cardId, listEl));
		});
	}

	/**
	 * One comment row: meta + delete button + a body that opens the Text editor
	 * (Markdown) on click, exactly like the card description.
	 */
	function renderComment(c, cardId, listEl) {
		const item = el('div', 'sk-comment');

		const meta = el('div', 'sk-comment-meta');
		meta.appendChild(el('span', null, c.author + ' — ' + formatDate(c.created_at)));
		const del = el('button', 'sk-comment-del', '✕');
		del.title = 'Supprimer ce commentaire';
		del.addEventListener('click', async function (e) {
			e.stopPropagation();
			if (!window.confirm('Supprimer ce commentaire ?')) { return; }
			const res = await api('DELETE', commentUrl(currentId, cardId, c.id));
			if (res.ok) { loadComments(cardId, listEl); }
			else { window.alert('Erreur ' + res.status); }
		});
		meta.appendChild(del);
		item.appendChild(meta);

		const body = el('div', 'sk-comment-body sk-rich');
		// Rendered Markdown (sanitized server-side); raw text as a fallback.
		if (c.body_html) { body.innerHTML = c.body_html; } else { body.textContent = c.body; }
		body.title = 'Cliquer pour éditer';
		body.addEventListener('click', function () { editComment(c, cardId, listEl, item, body); });
		item.appendChild(body);

		return item;
	}

	/**
	 * Swap a comment body for the Text editor (textarea fallback) + save/cancel.
	 */
	function editComment(c, cardId, listEl, item, bodyEl) {
		if (item.querySelector('.sk-comment-editwrap')) { return; }
		bodyEl.hidden = true;

		let md = c.body;
		let editor = null;
		const wrap = el('div', 'sk-comment-editwrap');
		const editorEl = el('div', 'sk-comment-editor');
		const fallback = el('textarea', 'sk-comment-input');
		fallback.value = md;
		fallback.hidden = true;
		fallback.addEventListener('input', function () { md = fallback.value; });

		loadTextEditor().then(function (createEditor) {
			if (!createEditor) { editorEl.hidden = true; fallback.hidden = false; return; }
			try {
				const result = createEditor({
					el: editorEl,
					content: md,
					useSession: false,
					autofocus: true,
					onUpdate: function (data) { md = data.markdown; },
				});
				Promise.resolve(result).then(function (inst) { editor = inst; })
					.catch(function () { editorEl.hidden = true; fallback.hidden = false; });
			} catch (e) { editorEl.hidden = true; fallback.hidden = false; }
		});

		const cleanup = function () {
			if (editor && typeof editor.destroy === 'function') { try { editor.destroy(); } catch (e) { /* ignore */ } }
		};

		const save = el('button', 'sk-btn sk-btn-primary', 'Enregistrer');
		save.addEventListener('click', async function () {
			const body = (md || '').trim();
			if (!body) { window.alert('Le commentaire ne peut pas être vide.'); return; }
			save.disabled = true;
			const res = await api('PUT', commentUrl(currentId, cardId, c.id), { body: body });
			if (res.ok) { cleanup(); loadComments(cardId, listEl); }
			else { save.disabled = false; window.alert('Erreur ' + res.status); }
		});
		const cancel = el('button', 'sk-btn', 'Annuler');
		cancel.addEventListener('click', function () { cleanup(); loadComments(cardId, listEl); });

		const actions = el('div', 'sk-comment-editactions');
		actions.appendChild(save);
		actions.appendChild(cancel);

		wrap.appendChild(editorEl);
		wrap.appendChild(fallback);
		wrap.appendChild(actions);
		item.appendChild(wrap);
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
		document.getElementById('sk-new-from-template').hidden = (currentId === null);
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

		const closeForm = function () { form.hidden = true; };
		const doSubmit = async function () {
			const name = nameInput.value.trim();
			if (!name) { nameInput.focus(); return; }
			submit.disabled = true;
			const payload = { name: name, color: colorInput.value };
			const res = (mode === 'create')
				? await api('POST', boardsUrl(), payload)
				: await api('PUT', boardUrl(board.id), payload);
			if (res.ok) {
				const data = await res.json();
				closeForm();
				await reload(data.board ? data.board.id : currentId);
			} else {
				submit.disabled = false;
				window.alert('Erreur ' + res.status);
			}
		};
		submit.addEventListener('click', doSubmit);
		cancel.addEventListener('click', closeForm);
		// Enter submits; Esc cancels — anywhere in the form.
		nameInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { doSubmit(); } });
		form.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); closeForm(); } });

		form.appendChild(nameInput);
		form.appendChild(colorInput);
		form.appendChild(submit);
		form.appendChild(cancel);
		if (mode === 'edit') {
			const del = el('button', 'sk-btn sk-btn-danger', 'Supprimer le tableau');
			del.addEventListener('click', async function () {
				if (!window.confirm('Supprimer le tableau « ' + board.name + ' » et son contenu ?')) { return; }
				del.disabled = true;
				const res = await api('DELETE', boardUrl(board.id));
				if (res.ok) { form.hidden = true; await reload(null); }
				else { del.disabled = false; window.alert('Erreur ' + res.status); }
			});
			form.appendChild(del);
		}
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

	/* ---- Templates & procedures (plain .md in Kanban/Modèles + Procédures) ---- */

	async function fetchList(url, key) {
		try {
			const res = await api('GET', url);
			if (res.ok) { const data = await res.json(); return data[key] || []; }
		} catch (e) { /* none */ }
		return [];
	}

	/**
	 * A floating menu of {name, meta} items anchored under a button. Closes on
	 * outside click or Esc. Calls onPick(item) on selection.
	 */
	function openMenu(anchorEl, items, onPick) {
		document.querySelectorAll('.sk-menu').forEach(function (m) { m.remove(); });
		const menu = el('div', 'sk-menu');
		if (!items.length) {
			menu.appendChild(el('div', 'sk-menu-empty', '(aucun)'));
		}
		items.forEach(function (it) {
			const icon = (it.meta && it.meta['icône']) ? it.meta['icône'] + ' ' : '';
			const b = el('button', 'sk-menu-item', icon + it.name);
			b.addEventListener('click', function () { menu.remove(); onPick(it); });
			menu.appendChild(b);
		});
		const r = anchorEl.getBoundingClientRect();
		menu.style.top = (r.bottom + 4) + 'px';
		menu.style.left = Math.max(8, r.left) + 'px';
		document.body.appendChild(menu);
		setTimeout(function () {
			const stop = function () {
				menu.remove();
				document.removeEventListener('mousedown', onDown, true);
				document.removeEventListener('keydown', onKey, true);
			};
			const onDown = function (e) { if (!menu.contains(e.target) && e.target !== anchorEl) { stop(); } };
			const onKey = function (e) { if (e.key === 'Escape') { e.preventDefault(); stop(); } };
			document.addEventListener('mousedown', onDown, true);
			document.addEventListener('keydown', onKey, true);
		}, 0);
	}

	async function pickTemplateAndCreate(anchorEl) {
		const board = currentBoard();
		if (!board) { return; }
		const templates = await fetchList(templatesUrl(), 'templates');
		openMenu(anchorEl, templates, async function (t) {
			const title = window.prompt('Titre de la carte', t.name);
			if (title === null) { return; }
			const cols = board.columns || [];
			const target = (t.meta && t.meta.colonne_cible && cols.indexOf(t.meta.colonne_cible) !== -1)
				? t.meta.colonne_cible
				: (cols[0] || '');
			if (!target) { window.alert('Ce tableau n’a aucune colonne.'); return; }
			const res = await api('POST', cardsUrl(currentId), {
				title: (title.trim() || t.name),
				column: target,
				description: t.body,
			});
			if (res.ok) { loadCards(board); }
			else { window.alert('Erreur ' + res.status); }
		});
	}

	function init() {
		try {
			const newBtn = document.getElementById('sk-new-board');
			const editBtn = document.getElementById('sk-edit-board');
			const tplBtn = document.getElementById('sk-new-from-template');
			if (tplBtn) {
				tplBtn.addEventListener('click', function () { pickTemplateAndCreate(tplBtn); });
			}
			if (newBtn) {
				newBtn.addEventListener('click', function () { showBoardForm('create', null); });
			}
			if (editBtn) {
				editBtn.addEventListener('click', function () {
					const board = currentBoard();
					if (board) { showBoardForm('edit', board); }
				});
			}
			reload(null).catch(function (e) {
				showMessage('Impossible de joindre l’API : ' + e.message);
			});
		} catch (e) {
			console.error('[Sovereign Kanban] init failed:', e);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
