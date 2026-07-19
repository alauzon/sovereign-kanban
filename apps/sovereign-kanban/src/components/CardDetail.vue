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
	<NcModal :size="expanded ? 'full' : 'large'" :can-close="false" @close="requestClose">
		<div class="sk-detail-vue" :class="{ 'sk-detail-vue--expanded': expanded }">
			<div v-if="confirmClose" class="sk-closeconfirm">
				<div class="sk-closeconfirm-box">
					<p class="sk-closeconfirm-msg">{{ t('Que voulez-vous faire de cette carte ?') }}</p>
					<div class="sk-closeconfirm-actions">
						<NcButton type="primary" :disabled="saving" @click="saveAndClose">
							{{ t('Enregistrer') }}
						</NcButton>
						<NcButton @click="$emit('close')">
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

			<input
				v-model="form.title"
				class="sk-detail-title-input"
				type="text"
				:readonly="readOnly"
				:placeholder="t('Titre')">

			<div v-if="readOnly" class="sk-readonly-banner">
				👁 {{ t('Lecture seule — vous ne pouvez pas modifier cette carte.') }}
			</div>

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

			<CommentsSection
				:board-id="boardId"
				:card-id="card.id"
				:read-only="readOnly" />
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { loadTextEditor } from '../text-editor.js'
import { prioLabel } from '../priority.js'
import CommentsSection from './CommentsSection.vue'
import DateField from './DateField.vue'

const PROCEDURES = '/apps/sovereign-kanban-md-persistence/api/v1/procedures'

export default {
	name: 'CardDetail',

	components: { NcModal, NcButton, NcActions, NcActionButton, NcActionCaption, NcSelect, CommentsSection, DateField },

	props: {
		boardId: { type: String, required: true },
		card: { type: Object, required: true },
		readOnly: { type: Boolean, default: false },
		// Every tag already used on the board, for suggestion (Alain, 2026-07-18).
		knownTags: { type: Array, default: () => [] },
		// The board's tag palette ([{name, color}]) for the label dropdown.
		palette: { type: Array, default: () => [] },
	},

	emits: ['saved', 'deleted', 'close'],

	data() {
		return {
			form: {
				title: this.card.title || '',
				description: this.card.description || '',
				priority: this.card.priority || '',
				phase: this.card.phase != null ? String(this.card.phase) : '',
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
			error: '',
			expanded: false,
			procedures: [],
			editorMounted: false,
			editorInstance: null,
			confirmClose: false,
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
	},

	mounted() {
		this.loadProcedures()
		this.loadBoardMembers()
		// Mount the rich editor once the DOM (and $refs.editorEl) exist.
		this.$nextTick(() => this.mountEditor(this.form.description))
	},

	beforeUnmount() {
		this.destroyEditor()
		clearTimeout(this.assigneeTimer)
	},

	methods: {
		t(s) {
			return s
		},

		prioLabel,

		// Closing (✕) offers Save / Discard / Delete instead of dropping edits
		// silently (Alain, 2026-07-18). Read-only has nothing to save → just close.
		requestClose() {
			if (this.readOnly) {
				this.$emit('close')
				return
			}
			this.confirmClose = true
		},

		async saveAndClose() {
			await this.save()
		},

		async loadProcedures() {
			try {
				const res = await axios.get(generateUrl(PROCEDURES))
				this.procedures = res.data.procedures || []
			} catch (e) {
				this.procedures = []
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
				})
				this.$emit('saved')
			} catch (e) {
				const status = e.response && e.response.status
				const code = e.response && e.response.data && e.response.data.error
				if (status === 401 || status === 403 || status >= 500) {
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
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 10px;
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
	justify-content: flex-end;
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

.sk-detail-title-input {
	font-size: 1.2em;
	font-weight: 600;
	width: 100%;
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
</style>
