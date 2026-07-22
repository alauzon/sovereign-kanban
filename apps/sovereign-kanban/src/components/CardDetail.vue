<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - Card editor in Vue (migration of the vanilla renderDetail). Title, dates,
  - assignees, priority, phase, tags, body, save/delete. Behind the
  - characterization test.
  -
  - Dates use <input type="datetime-local"> with the SAME contract as the vanilla
  - fix (2026-07-15/18): a stored date may be 'Y-m-d' (no time) or 'Y-m-dTHH:MM';
  - midnight in the picker means "no time" and round-trips back to a plain date,
  - so a card without a time never gets one. The server validates (400 on a
  - malformed date — Steve's 6-digit-year bug), so a rejected save surfaces here.
  -
  - The body is a plain textarea for now; Nextcloud's Text editor (D1) is a later
  - step. Read-only disables every write control — Steve's bare-403, in Vue.
-->
<template>
	<CardShell :docked="docked" :expanded="expanded" @close="requestClose">
		<div class="sk-detail-vue" :class="{ 'sk-detail-vue--expanded': expanded && !docked, 'sk-detail-vue--docked': docked }" @click="onLinkClick">
			<div v-if="confirmClose" class="sk-closeconfirm">
				<div class="sk-closeconfirm-box">
					<p class="sk-closeconfirm-msg">{{ t('Que voulez-vous faire de cette carte ?') }}</p>
					<div class="sk-closeconfirm-actions">
						<NcButton type="primary" :disabled="saving" @click="saveAndClose">
							{{ t('Enregistrer') }}
						</NcButton>
						<NcButton @click="discardAndClose">
							{{ t('Annuler') }}
						</NcButton>
						<NcButton type="error" @click="remove">
							{{ t('Supprimer') }}
						</NcButton>
						<NcButton type="tertiary" @click="confirmClose = false">
							{{ t('Continuer l\'édition') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div class="sk-detail-toolbar">
				<NcButton
					v-if="!readOnly"
					class="sk-done-btn"
					:type="completedAt ? 'success' : 'secondary'"
					@click="completedAt = completedAt ? null : nowIso()">
					{{ completedAt ? t('✓ Fait') : t('Marquer comme fait') }}
				</NcButton>
				<input
					v-model="form.title"
					class="sk-detail-title-input"
					type="text"
					:readonly="readOnly"
					:placeholder="t('Titre')">
				<NcButton
					v-if="!docked"
					type="tertiary"
					:aria-label="expanded ? t('Réduire l\'éditeur') : t('Agrandir l\'éditeur')"
					@click="expanded = !expanded">
					{{ expanded ? t('⤡ Réduire') : t('⤢ Plein écran') }}
				</NcButton>
				<NcButton
					type="tertiary"
					:aria-label="t('Fermer')"
					@click="requestClose">
					✕
				</NcButton>
			</div>

			<p v-if="card.created_at || card.modified || completedAt || card.author_label" class="sk-detail-summary">
				<span v-if="card.created_at">{{ t('Créé') }} {{ formatDate(card.created_at) }}</span>
				<span v-if="card.modified"> · {{ t('Modifié') }} {{ formatDate(card.modified) }}</span>
				<span v-if="card.author_label"> · {{ card.author_label }}</span>
				<span v-if="completedAt"> · ✓ {{ t('Terminé') }} {{ formatDate(completedAt) }}</span>
			</p>

			<div v-if="readOnly" class="sk-readonly-banner">
				👁 {{ t('Lecture seule — vous ne pouvez pas modifier cette carte.') }}
			</div>

			<div class="sk-detail-tabs" role="tablist">
				<button
					type="button"
					class="sk-tab"
					:class="{ 'sk-tab--on': tab === 'details' }"
					@click="tab = 'details'">
					<span class="sk-tab-ico" aria-hidden="true">🏠</span>
					<span>{{ t('Détails') }}</span>
				</button>
				<button
					type="button"
					class="sk-tab"
					:class="{ 'sk-tab--on': tab === 'attachments' }"
					@click="openAttachments">
					<span class="sk-tab-ico" aria-hidden="true">📎</span>
					<span>{{ t('Pièces jointes') }}</span>
				</button>
				<button
					type="button"
					class="sk-tab"
					:class="{ 'sk-tab--on': tab === 'comments' }"
					@click="tab = 'comments'">
					<span class="sk-tab-ico" aria-hidden="true">💬</span>
					<span>{{ t('Commentaires') }}</span>
				</button>
				<button
					type="button"
					class="sk-tab"
					:class="{ 'sk-tab--on': tab === 'activity' }"
					@click="openActivity">
					<span class="sk-tab-ico" aria-hidden="true">⚡</span>
					<span>{{ t('Activité') }}</span>
				</button>
			</div>

			<div v-show="tab === 'details'" class="sk-tab-panel">
			<div class="sk-field-row">
				<DateField v-model="startInput" class="sk-field" :label="t('Date de début')" :disabled="readOnly" />
				<DateField v-model="dueInput" class="sk-field" :label="t('Date de fin')" :disabled="readOnly" />
			</div>

			<label class="sk-field">
				<span>{{ t('Assignés') }}</span>
				<NcSelect
					v-model="assigneesSelected"
					:options="assigneeOptions"
					:multiple="true"
					:close-on-select="false"
					:user-select="true"
					:disabled="readOnly"
					label="displayName"
					input-label=""
					:placeholder="t('Assigner une personne…')" />
			</label>

			<div class="sk-field-row">
				<label class="sk-field">
					<span>{{ t('Phase') }}</span>
					<select v-model="form.phase" :disabled="readOnly">
						<option value="">{{ t('—') }}</option>
						<option v-for="p in phases" :key="p" :value="p">{{ t('Phase') }} {{ p }}</option>
					</select>
				</label>
				<label class="sk-field">
					<span>{{ t('Priorité') }}</span>
					<select v-model="form.priority" :disabled="readOnly">
						<option value="">{{ t('—') }}</option>
						<option v-for="p in priorities" :key="p" :value="p">{{ prioLabel(p) }}</option>
					</select>
				</label>
			</div>

			<div class="sk-field" v-if="!readOnly || form.linkedBoard">

				<span>{{ t('Tableau lié') }}</span>

				<div v-if="form.linkedBoard" class="sk-linkedboard">

					<button type="button" class="sk-openboard-btn" @click="openLinkedBoard">

						{{ linkedBoardName || form.linkedBoard }} &rarr;

					</button>

					<NcButton v-if="!readOnly" type="tertiary" :aria-label="t('Délier le tableau')" :title="t('Délier le tableau')" @click="form.linkedBoard = ''">✕</NcButton>

				</div>

				<div v-else-if="!readOnly" class="sk-linkedboard">

					<select v-model="form.linkedBoard" :disabled="creatingBoard">

						<option value="">{{ t('— choisir un tableau —') }}</option>

						<option v-for="b in linkableBoards" :key="b.id" :value="b.id">{{ b.name }}</option>

					</select>

					<NcButton type="secondary" :disabled="creatingBoard" @click="createLinkedBoard">

						{{ creatingBoard ? t('Création…') : t('Créer le tableau du projet') }}

					</NcButton>

				</div>

			</div>

			<label class="sk-field">

				<span>{{ t('Étiquettes') }}</span>
				<NcSelect
					v-model="selectedTags"
					:options="tagOptions"
					:multiple="true"
					:taggable="true"
					:close-on-select="false"
					:disabled="readOnly"
					input-label=""
					:placeholder="t('Sélectionner ou créer une étiquette')">
					<template #option="option">
						<span class="sk-tagchip" :style="chipStyle(optLabel(option))">{{ optLabel(option) }}</span>
					</template>
					<template #selected-option="option">
						<span class="sk-tagchip" :style="chipStyle(optLabel(option))">{{ optLabel(option) }}</span>
					</template>
				</NcSelect>
			</label>

			<div class="sk-field sk-desc-field">
				<div class="sk-desc-head">
					<span>{{ t('Description') }}</span>
					<NcActions v-if="!readOnly" :aria-label="t('Insérer une procédure')" :title="t('Insérer une procédure')">
						<template #icon>
							<span aria-hidden="true">＋</span>
						</template>
						<NcActionButton
							v-for="proc in procedures"
							:key="proc.name"
							@click="insertProcedure(proc)">
							{{ proc.name }}
						</NcActionButton>
						<NcActionCaption v-if="!procedures.length" :name="t('Aucune procédure')" />
					</NcActions>
				</div>
				<!-- Rich Text editor mounts here when available; textarea is the
				     fallback (and the read-only view). -->
				<div v-show="editorMounted" ref="editorEl" class="sk-desc-editor" />
				<textarea
					v-show="!editorMounted"
					v-model="form.description"
					class="sk-desc-fallback"
					rows="6"
					:readonly="readOnly"
					:placeholder="t('Description (Markdown)…')" />
			</div>

				<RelationsField
					class="sk-field"
					:board-id="boardId"
					:card-id="card.id"
					:relations="card.relations || []"
					:board-cards="boardCards"
					:read-only="readOnly"
					@changed="onRelationsChanged" />
			</div>

			<div v-show="tab === 'attachments'" class="sk-tab-panel">
				<AttachmentsSection
					v-if="attachmentsSeen"
					:board-id="boardId"
					:card-id="card.id"
					:read-only="readOnly"
					@changed="onAttachmentsChanged" />
			</div>

			<div v-show="tab === 'comments'" class="sk-tab-panel">
				<CommentsSection
					:board-id="boardId"
					:card-id="card.id"
					:read-only="readOnly" />
			</div>

			<div v-show="tab === 'activity'" class="sk-tab-panel">
				<p v-if="activityLoading" class="sk-activity-empty">{{ t('Chargement…') }}</p>
				<p v-else-if="!activity.length" class="sk-activity-empty">{{ t('Aucune activité pour l\'instant.') }}</p>
				<ol v-else class="sk-activity-list">
					<li v-for="(ev, i) in activityReversed" :key="i" class="sk-activity-item">
						<span class="sk-activity-dot" aria-hidden="true">{{ activityIcon(ev.action) }}</span>
						<span class="sk-activity-text">
							<strong>{{ ev.actor_label || t('Quelqu\'un') }}</strong>
							{{ activityVerb(ev) }}
						</span>
						<time class="sk-activity-time">{{ formatDate(ev.ts) }}</time>
					</li>
				</ol>
			</div>

			<p v-if="error" class="sk-detail-error">{{ error }}</p>

			<div class="sk-detail-actions">
				<NcButton v-if="!readOnly" type="error" @click="remove">
					{{ t('Supprimer') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="readOnly || saving"
					@click="save">
					{{ t('Enregistrer') }}
				</NcButton>
			</div>
		</div>
	</CardShell>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import CardShell from './CardShell.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { loadTextEditor } from '../text-editor.js'
import { prioLabel } from '../priority.js'
import CommentsSection from './CommentsSection.vue'
import DateField from './DateField.vue'
import RelationsField from './RelationsField.vue'
import AttachmentsSection from './AttachmentsSection.vue'

const PROCEDURES = '/apps/sovereign-kanban-md-persistence/api/v1/procedures'

export default {
	name: 'CardDetail',

	components: { CardShell, NcButton, NcActions, NcActionButton, NcActionCaption, NcSelect, CommentsSection, DateField, RelationsField, AttachmentsSection },

	props: {
		docked: { type: Boolean, default: false },
		boardId: { type: String, required: true },
		boards: { type: Array, default: () => [] },
		card: { type: Object, required: true },
		readOnly: { type: Boolean, default: false },
		// Every tag already used on the board, for suggestion (Alain, 2026-07-18).
		knownTags: { type: Array, default: () => [] },
		// The board's tag palette ([{name, color}]) for the label dropdown.
		palette: { type: Array, default: () => [] },
		// Flat list of the board's cards ([{id, title}]) for the relation search.
		boardCards: { type: Array, default: () => [] },
	},

	emits: ['saved', 'deleted', 'close', 'refresh'],

	data() {
		return {
			form: {
				title: this.card.title || '',
				description: this.card.description || '',
				priority: this.card.priority || '',
				phase: this.card.phase != null ? String(this.card.phase) : '',
				linkedBoard: this.card.linked_board || '',
			},
			startInput: this.toInput(this.card.start_date),
			dueInput: this.toInput(this.card.due_date),
			assigneesSelected: (this.card.assignees || []).map((uid) => ({ id: uid, displayName: uid })),
			assigneeOptions: [],
			assigneeTimer: null,
			selectedTags: [...(this.card.tags || [])],
			priorities: ['1', '2', '3', '4', '5'],
			phases: ['1', '2', '3', '4'],
			saving: false,
			creatingBoard: false,
			error: '',
			expanded: false,
			procedures: [],
			editorMounted: false,
			editorInstance: null,
			confirmClose: false,
			// Set true when the user explicitly discards/deletes, so beforeUnmount's
			// auto-save does not resurrect the abandoned edits (Alain, 2026-07-20).
			skipSave: false,
			completedAt: this.card.completed_at || null,
			tab: 'details',
			activity: [],
			activityLoading: false,
			activityLoaded: false,
			attachmentsSeen: false,
		}
	},

	computed: {
		// Options for the label dropdown: the board palette plus any tag already on
		// the card that isn't in the palette (so it stays selectable/visible).
		tagOptions() {
			const names = new Set(this.palette.map((t) => t.name))
			;(this.card.tags || []).forEach((t) => names.add(t))
			return [...names]
		},

		// Journal newest-first for the panel (the file stores it oldest-first).
		activityReversed() {
			return [...this.activity].reverse()
		},

		// Whether the editable fields differ from the card as loaded (Alain,
		// 2026-07-19: the ✕ closes straight away when nothing changed, instead of
		// asking). Colour/archive/relations save on their own, so they don't count.
		// Description is compared trimmed — the rich editor can re-emit a trailing
		// newline on mount, which is not a real edit.
		linkedBoardName() {
			const b = (this.boards || []).find((x) => x.id === this.form.linkedBoard)
			return b ? b.name : ''
		},

		// Boards this card can link to — anything but its own board.
		linkableBoards() {
			return (this.boards || []).filter((b) => b.id !== this.boardId)
		},

		isDirty() {
			const c = this.card
			const norm = (v) => (v === null || v === undefined) ? '' : String(v)
			if (this.form.title.trim() !== norm(c.title).trim()) {
				return true
			}
			if (norm(this.form.description).trim() !== norm(c.description).trim()) {
				return true
			}
			if (norm(this.form.priority) !== norm(c.priority)) {
				return true
			}
			if (norm(this.form.phase) !== (c.phase != null ? String(c.phase) : '')) {
				return true
			}
			if ((this.form.linkedBoard || '') !== (c.linked_board || '')) {
				return true
			}
			if (norm(this.fromInput(this.startInput)) !== norm(c.start_date)) {
				return true
			}
			if (norm(this.fromInput(this.dueInput)) !== norm(c.due_date)) {
				return true
			}
			if (norm(this.completedAt) !== norm(c.completed_at)) {
				return true
			}
			if (JSON.stringify(this.assigneesSelected.map((a) => a.id)) !== JSON.stringify(c.assignees || [])) {
				return true
			}
			if (JSON.stringify(this.selectedTags.map((t) => this.optLabel(t))) !== JSON.stringify(c.tags || [])) {
				return true
			}
			return false
		},
	},

	mounted() {
		this.loadProcedures()
		this.loadBoardMembers()
		// Mount the rich editor once the DOM (and $refs.editorEl) exist.
		this.$nextTick(() => this.mountEditor(this.form.description))
	},

	beforeUnmount() {
		// Auto-save on switching cards (Alain, 2026-07-20). :key remounts us when
		// the open card changes, so persist the leaving card's edits here — unless
		// the user explicitly discarded/deleted (skipSave). Fire-and-forget: the PUT
		// outlives this component.
		if (!this.readOnly && this.isDirty && !this.skipSave) {
			this.save()
		}
		this.destroyEditor()
		clearTimeout(this.assigneeTimer)
	},

	methods: {
		t(s) {
			return s
		},

		prioLabel,

		// Open http(s) links in a new tab on a plain click, anywhere in the card
		// (description, comments…) — Alain, 2026-07-19. Modifier-clicks keep their
		// default so text selection / editing still works.
		onLinkClick(ev) {
			if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) {
				return
			}
			const a = ev.target.closest && ev.target.closest('a[href]')
			if (!a) {
				return
			}
			const href = a.getAttribute('href') || ''
			if (/^https?:\/\//i.test(href)) {
				ev.preventDefault()
				window.open(href, '_blank', 'noopener,noreferrer')
			}
		},

		nowIso() {
			return new Date().toISOString()
		},

		formatDate(iso) {
			try {
				return new Date(iso).toLocaleString('fr-CA', { dateStyle: 'medium', timeStyle: 'short' })
			} catch (e) {
				return iso
			}
		},

		// Closing (✕) offers Save / Discard / Delete instead of dropping edits
		// silently (Alain, 2026-07-18). Read-only has nothing to save → just close.
		requestClose() {
			// Nothing to lose → close straight away (Alain, 2026-07-19). Only ask
			// when there are unsaved edits.
			if (this.readOnly || !this.isDirty) {
				this.$emit('close')
				return
			}
			this.confirmClose = true
		},

		async saveAndClose() {
			await this.save()
			this.skipSave = true
		},

		// « Annuler » in the close dialog: close WITHOUT saving. Mark skipSave so the
		// beforeUnmount auto-save does not bring the discarded edits back.
		discardAndClose() {
			this.skipSave = true
			this.$emit('close')
		},

		// « Ouvrir → » : navigate to the linked board via the URL hash, closing the
		// editor (its unsaved edits auto-save on unmount). Alain, 2026-07-20.
		openLinkedBoard() {
			if (!this.form.linkedBoard) {
				return
			}
			window.location.hash = '#' + encodeURIComponent(this.form.linkedBoard)
			this.$emit('close')
		},

		// « Créer le tableau du projet » : create a board named after the card, link it,
		// and save. If the name already exists (409) link to the existing one.
		async createLinkedBoard() {
			if (this.readOnly || this.creatingBoard) {
				return
			}
			const name = (this.form.title || this.card.title || '').trim()
			if (!name) {
				this.error = this.t('Donnez d\'abord un titre à la carte.')
				return
			}
			this.creatingBoard = true
			try {
				const res = await axios.post(generateUrl('/apps/sovereign-kanban-md-persistence/api/v1/boards'), { name })
				this.form.linkedBoard = res.data.board.id
				await this.save()
				this.$emit('refresh')
			} catch (e) {
				const code = e.response && e.response.data && e.response.data.error
				if (code === 'board_exists' && e.response.data.id) {
					this.form.linkedBoard = e.response.data.id
					await this.save()
					this.$emit('refresh')
				} else {
					this.error = this.t('Impossible de créer le tableau.')
				}
			} finally {
				this.creatingBoard = false
			}
		},

		async loadProcedures() {
			try {
				const res = await axios.get(generateUrl(PROCEDURES))
				this.procedures = res.data.procedures || []
			} catch (e) {
				this.procedures = []
			}
		},

		// A relation was added/removed (RelationsField already hit the API): refresh
		// the board so a newly created linked card appears, and reload the journal
		// if it is showing (the link is a journaled event).
		onRelationsChanged() {
			this.$emit('refresh')
			if (this.activityLoaded) {
				this.loadActivity()
			}
		},

		// Open the Pièces jointes tab; latch the section so it mounts once (lazy).
		openAttachments() {
			this.tab = 'attachments'
			this.attachmentsSeen = true
		},

		// An attachment was added/removed: reload the journal if it is showing
		// (attach/detach are journaled events).
		onAttachmentsChanged() {
			if (this.activityLoaded) {
				this.loadActivity()
			}
		},

		// Open the Activité tab, loading the sovereign journal once (option C).
		openActivity() {
			this.tab = 'activity'
			if (!this.activityLoaded) {
				this.loadActivity()
			}
		},

		async loadActivity() {
			this.activityLoading = true
			try {
				const res = await axios.get(this.url() + '/activity')
				this.activity = res.data.activity || []
				this.activityLoaded = true
			} catch (e) {
				this.activity = []
			} finally {
				this.activityLoading = false
			}
		},

		// A glyph per event kind — a quiet visual anchor, never load-bearing.
		activityIcon(action) {
			return {
				created: '✨',
				updated: '✏️',
				moved: '➡️',
				commented: '💬',
				done: '✓',
				reopened: '↺',
				linked: '🔗',
				unlinked: '✂️',
				attached: '📎',
				detached: '🗑️',
			}[action] || '•'
		},

		// French sentence for one journal event. Field ids (stored in English) are
		// translated here for display — the record stays identifier-stable.
		activityVerb(ev) {
			const fieldLabels = {
				title: this.t('le titre'),
				description: this.t('la description'),
				due_date: this.t('la date de fin'),
				start_date: this.t('la date de début'),
				assignees: this.t('les assignés'),
				priority: this.t('la priorité'),
				tags: this.t('les étiquettes'),
				phase: this.t('la phase'),
			}
			switch (ev.action) {
			case 'created':
				return this.t('a créé la carte')
			case 'commented':
				return this.t('a commenté')
			case 'done':
				return this.t('a marqué la carte comme faite')
			case 'reopened':
				return this.t('a rouvert la carte')
			case 'moved':
				return this.t('a déplacé la carte')
			case 'linked':
				return this.t('a lié une carte')
			case 'unlinked':
				return this.t('a délié une carte')
			case 'attached':
				return this.t('a joint') + (ev.detail && ev.detail.name ? ' ' + ev.detail.name : ' un fichier')
			case 'detached':
				return this.t('a retiré') + (ev.detail && ev.detail.name ? ' ' + ev.detail.name : ' un fichier')
			case 'updated': {
				const fields = (ev.detail && ev.detail.fields) || []
				const names = fields.map((f) => fieldLabels[f] || f)
				return names.length
					? this.t('a modifié') + ' ' + names.join(', ')
					: this.t('a modifié la carte')
			}
			default:
				return ev.action
			}
		},

		// Mount (or remount) Nextcloud's Text editor on the description. Read-only
		// keeps the plain textarea; a missing Text module falls back to it too.
		async mountEditor(content) {
			if (this.readOnly) {
				return
			}
			const createEditor = await loadTextEditor()
			if (!createEditor || !this.$refs.editorEl) {
				this.editorMounted = false
				return
			}
			await this.destroyEditor()
			try {
				const inst = await createEditor({
					el: this.$refs.editorEl,
					content,
					useSession: false,
					autofocus: false,
					onUpdate: (data) => {
						this.form.description = data.markdown
					},
				})
				this.editorInstance = inst
				this.editorMounted = true
			} catch (e) {
				this.editorMounted = false
			}
		},

		async destroyEditor() {
			if (this.editorInstance && typeof this.editorInstance.destroy === 'function') {
				try {
					await this.editorInstance.destroy()
				} catch (e) {
					// ignore
				}
			}
			this.editorInstance = null
			if (this.$refs.editorEl) {
				this.$refs.editorEl.innerHTML = ''
			}
		},

		// Append a procedure snippet to the body, then remount so the rich editor
		// shows it (mirrors the vanilla "+ Procédure" behaviour).
		insertProcedure(proc) {
			const base = this.form.description ? this.form.description.replace(/\s+$/, '') + '\n\n' : ''
			this.form.description = base + proc.body
			this.mountEditor(this.form.description)
		},

		// NcSelect may hand a string option or an object; normalise to the label.
		optLabel(option) {
			return (option && option.label !== undefined) ? option.label : option
		},

		// Background colour for a tag chip, from the palette.
		chipStyle(name) {
			const found = this.palette.find((t) => t.name === name)
			if (found && found.color) {
				return { background: found.color, color: '#fff', borderColor: found.color }
			}
			return {}
		},

		// Assignee options = the board's members (owner + user shares), like Deck:
		// all are shown on click and NcSelect filters them locally as you type
		// (Alain, 2026-07-18). The sharees API rejects an empty search, so we build
		// the list from the shares instead of enumerating users.
		async loadBoardMembers() {
			const members = []
			const seen = new Set()
			const push = (id, displayName) => {
				if (id && !seen.has(id)) {
					seen.add(id)
					members.push({ id, displayName: displayName || id })
				}
			}
			const cur = (window.OC && window.OC.getCurrentUser) ? window.OC.getCurrentUser() : null
			if (cur && cur.uid) {
				push(cur.uid, cur.displayName)
			}
			try {
				const res = await axios.get(generateUrl(
					'/apps/sovereign-kanban-md-persistence/api/v1/boards/' + encodeURIComponent(this.boardId) + '/shares',
				))
				;(res.data.shares || []).forEach((s) => {
					if (s.type === 'user') {
						push(s.with, s.with)
					}
				})
			} catch (e) {
				// A received board can't list its shares; fall back to what we have.
			}
			// Keep anyone already assigned even if not in the member list.
			;(this.card.assignees || []).forEach((uid) => push(uid, uid))
			this.assigneeOptions = members
		},

		// 'Y-m-d' → 'Y-m-dT00:00' (the picker needs a time); a full date-time is
		// passed through. Empty stays empty.
		toInput(value) {
			if (!value) {
				return ''
			}
			return value.length === 10 ? value + 'T00:00' : value
		},

		// Midnight means "no time was chosen" → store a plain date; else keep it.
		fromInput(value) {
			if (!value) {
				return ''
			}
			return value.endsWith('T00:00') ? value.slice(0, 10) : value
		},

		splitList(s) {
			return s.split(',').map((x) => x.trim()).filter(Boolean)
		},

		url() {
			return generateUrl(
				'/apps/sovereign-kanban-md-persistence/api/v1/boards/'
				+ encodeURIComponent(this.boardId) + '/cards/' + encodeURIComponent(this.card.id),
			)
		},

		async save() {
			this.saving = true
			this.error = ''
			try {
				await axios.put(this.url(), {
					title: this.form.title.trim(),
					description: this.form.description,
					start_date: this.fromInput(this.startInput),
					due_date: this.fromInput(this.dueInput),
					assignees: this.assigneesSelected.map((a) => a.id),
					priority: this.form.priority,
					tags: this.selectedTags.map((t) => this.optLabel(t)),
					phase: this.form.phase,
					linked_board: this.form.linkedBoard,
					completed_at: this.completedAt === null ? '' : this.completedAt,
					// The rev we opened the card at, so a stale save is refused rather
					// than silently overwriting a concurrent edit (Nisha, carte 523b3b).
					baseRev: this.card.rev,
				})
				this.$emit('saved')
			} catch (e) {
				const status = e.response && e.response.status
				const code = e.response && e.response.data && e.response.data.error
				if (status === 409 && code === 'conflict') {
					// Someone saved this card while it was open. Blocking alert (survives
					// the re-render), then refresh the tiles and close so the user reopens
					// the up-to-date card — their edit is refused, not the other's lost.
					// eslint-disable-next-line no-alert
					window.alert(this.t('La carte a été modifiée par quelqu\'un d\'autre. Ta version n\'a pas été enregistrée — rouvre la carte à jour et refais ta modification.'))
					this.skipSave = true
					this.$emit('refresh')
					this.$emit('close')
				} else if (status === 401 || status === 403 || status >= 500) {
					// A stale session/CSRF token after the page sat open a long time
					// returns HTML, not our JSON (Alain, 2026-07-18).
					this.error = this.t('La session a peut-être expiré. Rafraîchissez la page (F5), puis réessayez.')
				} else if (code === 'invalid_date') {
					this.error = this.t('Date invalide.')
				} else if (code === 'invalid_assignee') {
					this.error = this.t('Un assigné n\'existe pas comme compte.')
				} else {
					this.error = this.t('Erreur à l\'enregistrement.')
				}
			} finally {
				this.saving = false
			}
		},

		async remove() {
			if (!window.confirm(this.t('Supprimer cette carte ?'))) {
				return
			}
			await axios.delete(this.url())
			this.$emit('deleted')
		},
	},
}
</script>

<style scoped>
.sk-detail-vue {
	padding: 12px 20px 14px;
	display: flex;
	flex-direction: column;
	gap: 7px;
	min-width: 420px;
}

/* Close confirmation (Alain, 2026-07-18): the ✕ asks Save / Discard / Delete. */
.sk-closeconfirm {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.4);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.sk-closeconfirm-box {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large, 12px);
	padding: 20px 24px;
	max-width: 420px;
	box-shadow: 0 2px 16px rgba(0, 0, 0, 0.3);
}

.sk-closeconfirm-msg {
	margin: 0 0 14px;
	font-weight: 500;
}

.sk-closeconfirm-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	justify-content: flex-end;
}

/* Plein écran (Alain, 2026-07-18): the modal takes the whole viewport, so the
   form gets the full width for editing long descriptions. */
.sk-detail-vue--expanded {
	min-width: 0;
	height: 100%;
}

.sk-detail-toolbar {
	display: flex;
	align-items: center;
	gap: 4px;
}

.sk-detail-tabs {
	display: flex;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
}

/* Tabs with an icon over the label, and a filled background on the active one
   (Alain, 2026-07-19, façon Deck). */
.sk-tab {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 3px;
	background: none;
	border: none;
	border-bottom: 2px solid transparent;
	border-top-left-radius: 8px;
	border-top-right-radius: 8px;
	padding: 8px 16px 7px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	font-size: 13px;
}

.sk-tab:hover {
	background: var(--color-background-hover);
}

.sk-tab-ico {
	font-size: 17px;
	line-height: 1;
}

.sk-tab--on {
	color: var(--color-primary-element);
	background: color-mix(in srgb, var(--color-primary-element) 16%, transparent);
	border-bottom-color: var(--color-primary-element);
	font-weight: 700;
}

/* Constant height across tabs (Alain, 2026-07-19): the panel keeps the same
   height whatever the active tab, and scrolls inside — no more jump. In plein
   écran the panel flexes to fill the freed vertical space instead. */
.sk-tab-panel {
	display: flex;
	flex-direction: column;
	gap: 10px;
	height: 54vh;
	overflow-y: auto;
	overflow-x: hidden;
	padding-right: 2px;
}

.sk-detail-vue--expanded .sk-tab-panel,
.sk-detail-vue--docked .sk-tab-panel {
	height: auto;
	flex: 1 1 auto;
	min-height: 0;
}

/* Docked to the right of the board: fill the dock's height and scroll inside. */
.sk-detail-vue--docked {
	height: 100%;
	min-width: 0;
	padding: 10px 14px 12px;
}

/* The datetime-local picker draws its calendar indicator at the right edge; if
   the field keeps its narrow intrinsic width the indicator overlaps the value
   (Alain, 2026-07-18: last date digit hidden). Full width clears it, and gives
   the description its full width too. */
.sk-field > input,
.sk-field > select,
.sk-field > textarea {
	width: 100%;
	box-sizing: border-box;
}

/* In plein écran the description grows to fill the freed vertical space. */
.sk-detail-vue--expanded .sk-desc-field {
	flex: 1 1 auto;
	min-height: 0;
}

.sk-detail-vue--expanded .sk-desc-fallback {
	height: 100%;
	min-height: 200px;
	resize: vertical;
}

.sk-detail-vue--expanded .sk-desc-editor {
	max-height: none;
	flex: 1 1 auto;
}

.sk-desc-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sk-desc-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.sk-desc-head > span {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-desc-editor {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	min-height: 160px;
	max-height: 40vh;
	overflow-y: auto;
	padding: 4px 8px;
}

.sk-desc-fallback {
	width: 100%;
	box-sizing: border-box;
	min-height: 140px;
}

/* Title shares the toolbar row with Marquer fait / Réduire / ✕ (Alain,
   2026-07-19: save vertical space) — it takes the middle, flexing. */
.sk-detail-title-input {
	flex: 1 1 auto;
	min-width: 0;
	font-size: 1.15em;
	font-weight: 600;
}

.sk-detail-summary {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

/* Sovereign activity journal (option C) — one line per event, newest first. */
.sk-activity-empty {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}

.sk-activity-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sk-activity-item {
	display: flex;
	align-items: baseline;
	gap: 8px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.sk-activity-dot {
	flex: 0 0 auto;
	width: 1.4em;
	text-align: center;
}

.sk-activity-text {
	flex: 1 1 auto;
	min-width: 0;
}

.sk-activity-time {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	font-size: 85%;
	white-space: nowrap;
}

.sk-tagchip {
	display: inline-block;
	font-size: 90%;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 0 10px;
	line-height: 1.6;
}

.sk-readonly-banner {
	padding: 6px 12px;
	border-radius: var(--border-radius, 8px);
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-element-text, #fff);
}

.sk-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

/* Two fields side by side (dates; priority + phase) — Alain, 2026-07-18. */
.sk-field-row {
	display: flex;
	gap: 12px;
}

.sk-field-row > .sk-field {
	flex: 1 1 0;
	min-width: 0;
}

.sk-field > span {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-detail-error {
	color: var(--color-error);
}

.sk-detail-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

<!-- Non-scoped: NcModal is teleported to <body>, so a scoped rule can't reach
     its native close button. Hide it only on the card modal (the one containing
     .sk-detail-vue) — we use our own ✕ that asks Save/Discard/Delete (Alain,
     2026-07-18: two ✕ were showing). -->
<style>
.modal-container:has(.sk-detail-vue) .modal-container__close {
	display: none;
}
.sk-linkedboard {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.sk-openboard-btn {
	font-weight: 600;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	border: none;
	border-radius: var(--border-radius);
	padding: 4px 12px;
	cursor: pointer;
}

</style>
