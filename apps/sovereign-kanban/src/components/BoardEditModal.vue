<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - Board create/edit modal in Vue — the migration of the vanilla showBoardForm
  - (Alain, 2026-07-18: "il manque le menu par tableau"). Name + colour + tag
  - palette are saved on submit (PUT/POST); columns are edited live with atomic
  - API calls (add/rename/remove/reorder), mirroring the vanilla behaviour.
  -
  - Sharing is NOT here yet — it is the NcSelect-users step (D12), a larger piece;
  - until then a board is shared through the vanilla app.
-->
<template>
	<NcModal size="normal" @close="$emit('close')">
		<div class="sk-boardedit">
			<h3 class="sk-boardedit-title">{{ isCreate ? t('Nouveau tableau') : t('Éditer le tableau') }}</h3>

			<label class="sk-field">
				<span>{{ t('Nom du tableau') }}</span>
				<input
					ref="nameInput"
					v-model="name"
					type="text"
					:placeholder="t('Nom du tableau')"
					@keyup.enter="submit">
			</label>

			<label class="sk-field sk-field--color">
				<span>{{ t('Couleur') }}</span>
				<input v-model="color" type="color">
			</label>

			<section v-if="!isCreate" class="sk-field">
				<span>{{ t('Colonnes') }}</span>
				<div
					v-for="(col, i) in localColumns"
					:key="col"
					class="sk-col-row"
					:class="{ 'sk-col-row--drag': dragIndex === i }"
					draggable="true"
					@dragstart="onColDragStart(i)"
					@dragover.prevent
					@drop="onColDrop(i)"
					@dragend="dragIndex = null">
					<span class="sk-col-handle" :title="t('Glisser pour réordonner')" aria-hidden="true">⠿</span>
					<input
						v-model="colDraft[col]"
						type="text"
						@keyup.enter="renameColumn(col)">
					<NcButton
						type="tertiary"
						:aria-label="t('Renommer la colonne')"
						:disabled="busy || !colDraft[col] || colDraft[col] === col"
						@click="renameColumn(col)">
						✓
					</NcButton>
					<NcButton
						type="error"
						:aria-label="t('Supprimer la colonne')"
						:disabled="busy"
						@click="removeColumn(col)">
						✕
					</NcButton>
				</div>
				<div class="sk-col-add">
					<input
						v-model="newColumn"
						type="text"
						:placeholder="t('Nouvelle colonne')"
						@keyup.enter="addColumn">
					<NcButton :disabled="busy || !newColumn.trim()" @click="addColumn">
						{{ t('+ Colonne') }}
					</NcButton>
				</div>
			</section>

			<section v-if="!isCreate" class="sk-field">
				<span>{{ t('Palette d\'étiquettes') }}</span>
				<div
					v-for="(row, i) in palette"
					:key="i"
					class="sk-palette-row">
					<input v-model="row.color" type="color">
					<input v-model="row.name" type="text" :placeholder="t('nom de l\'étiquette')">
					<NcButton type="error" :aria-label="t('Retirer de la palette')" @click="palette.splice(i, 1)">
						✕
					</NcButton>
				</div>
				<NcButton @click="palette.push({ name: '', color: '#0082c9' })">
					{{ t('+ Étiquette') }}
				</NcButton>
			</section>

			<!-- Sharing: owner only (a board shared *with* me can't be reshared). -->
			<SharePanel v-if="!isCreate && !board.shared" :board-id="board.id" />

			<p v-if="error" class="sk-boardedit-error">{{ error }}</p>

			<div class="sk-boardedit-actions">
				<NcButton
					v-if="!isCreate"
					type="error"
					:disabled="busy"
					@click="destroy">
					{{ t('Supprimer le tableau') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || !name.trim()"
					@click="submit">
					{{ isCreate ? t('Créer') : t('Enregistrer') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import SharePanel from './SharePanel.vue'

const BASE = '/apps/sovereign-kanban-md-persistence/api/v1/boards'

export default {
	name: 'BoardEditModal',

	components: { NcModal, NcButton, SharePanel },

	props: {
		// null → create; a board object → edit.
		board: { type: Object, default: null },
	},

	emits: ['saved', 'deleted', 'refresh', 'close'],

	data() {
		return {
			name: this.board ? this.board.name : '',
			color: (this.board && this.board.color) || '#0082c9',
			localColumns: this.board ? [...(this.board.columns || [])] : [],
			colDraft: this.initColDraft(),
			palette: (this.board && this.board.tags ? this.board.tags : []).map((t) => ({
				name: t.name,
				color: t.color || '#0082c9',
			})),
			newColumn: '',
			saving: false,
			busy: false,
			error: '',
			dragIndex: null,
		}
	},

	computed: {
		isCreate() {
			return !this.board
		},
	},

	mounted() {
		this.$nextTick(() => this.$refs.nameInput && this.$refs.nameInput.focus())
	},

	methods: {
		t(s) {
			return s
		},

		initColDraft() {
			const out = {}
			;(this.board && this.board.columns ? this.board.columns : []).forEach((c) => {
				out[c] = c
			})
			return out
		},

		boardUrl() {
			return generateUrl(BASE + '/' + encodeURIComponent(this.board.id))
		},

		columnsUrl() {
			return this.boardUrl() + '/columns'
		},

		async submit() {
			const name = this.name.trim()
			if (!name) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const payload = { name, color: this.color }
				if (!this.isCreate) {
					payload.tags = this.palette.filter((t) => t.name.trim()).map((t) => ({
						name: t.name.trim(),
						color: t.color,
					}))
				}
				const res = this.isCreate
					? await axios.post(generateUrl(BASE), payload)
					: await axios.put(this.boardUrl(), payload)
				this.$emit('saved', res.data && res.data.board ? res.data.board.id : null)
			} catch (e) {
				this.error = (e.response && e.response.status === 409)
					? this.t('Un tableau porte déjà ce nom.')
					: this.t('Erreur à l\'enregistrement.')
			} finally {
				this.saving = false
			}
		},

		async addColumn() {
			const name = this.newColumn.trim()
			if (!name || this.busy) {
				return
			}
			await this.columnOp(async () => {
				await axios.post(this.columnsUrl(), { name })
				this.localColumns.push(name)
				this.colDraft[name] = name
				this.newColumn = ''
			})
		},

		async renameColumn(from) {
			const to = (this.colDraft[from] || '').trim()
			if (!to || to === from || this.busy) {
				return
			}
			await this.columnOp(async () => {
				await axios.put(this.columnsUrl() + '/rename', { from, to })
				const i = this.localColumns.indexOf(from)
				if (i !== -1) {
					this.localColumns.splice(i, 1, to)
				}
				delete this.colDraft[from]
				this.colDraft[to] = to
			})
		},

		async removeColumn(name) {
			if (this.busy || !window.confirm(this.t('Supprimer la colonne « ') + name + ' » ?')) {
				return
			}
			await this.columnOp(async () => {
				await axios.delete(this.columnsUrl(), { data: { name } })
				const i = this.localColumns.indexOf(name)
				if (i !== -1) {
					this.localColumns.splice(i, 1)
				}
				delete this.colDraft[name]
			})
		},

		onColDragStart(index) {
			this.dragIndex = index
		},

		// Drop a dragged column onto another → reorder (Alain, 2026-07-18: drag
		// instead of ↑/↓ arrows).
		async onColDrop(targetIndex) {
			const from = this.dragIndex
			this.dragIndex = null
			if (from === null || from === targetIndex || this.busy) {
				return
			}
			const next = [...this.localColumns]
			const [moved] = next.splice(from, 1)
			next.splice(targetIndex, 0, moved)
			await this.columnOp(async () => {
				await axios.put(this.columnsUrl() + '/reorder', { columns: next })
				this.localColumns = next
			})
		},

		// A column mutation: run it, surface errors, tell the parent to reload the
		// board (columns changed, cards may have moved).
		async columnOp(fn) {
			this.busy = true
			this.error = ''
			try {
				await fn()
				this.$emit('refresh')
			} catch (e) {
				this.error = (e.response && e.response.status === 409)
					? this.t('Ce nom de colonne est déjà pris.')
					: this.t('Erreur sur la colonne.')
			} finally {
				this.busy = false
			}
		},

		async destroy() {
			if (!window.confirm(this.t('Supprimer le tableau « ') + this.board.name + this.t(' » et son contenu ?'))) {
				return
			}
			this.busy = true
			try {
				await axios.delete(this.boardUrl())
				this.$emit('deleted')
			} catch (e) {
				this.error = this.t('Erreur à la suppression.')
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.sk-boardedit {
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 420px;
}

.sk-boardedit-title {
	margin: 0;
}

.sk-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sk-field > span {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-field > input[type="text"] {
	width: 100%;
	box-sizing: border-box;
}

.sk-field--color > input {
	width: 60px;
	height: 32px;
	padding: 0;
}

.sk-col-row,
.sk-col-add,
.sk-palette-row {
	display: flex;
	gap: 4px;
	align-items: center;
}

.sk-col-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	padding: 0 2px;
	user-select: none;
}

.sk-col-row--drag {
	opacity: 0.5;
}

.sk-col-row > input[type="text"],
.sk-col-add > input,
.sk-palette-row > input[type="text"] {
	flex: 1 1 auto;
	min-width: 0;
}

.sk-boardedit-error {
	color: var(--color-error);
	margin: 0;
}

.sk-boardedit-actions {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	margin-top: 8px;
}
</style>
