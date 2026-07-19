<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - The board content in Vue: columns (from board.columns, in order) each holding
  - the cards the API returns for that column. This is the migration of the
  - vanilla renderColumns/showAddCard, behind the characterization test.
  -
  - Read-only awareness: a board shared without write permission hides the write
  - actions and shows the banner — same rule as the vanilla fix (shared &&
  - !(permissions & 2)), the same bug Steve hit on 2026-07-18.
-->
<template>
	<div class="sk-vue-columns">
		<div v-if="readOnly" class="sk-readonly-banner">
			👁 {{ t('Lecture seule — ce tableau vous est partagé sans droit de modification.') }}
		</div>
		<section
			v-for="column in board.columns"
			:key="column"
			class="sk-vue-column"
			:class="{ 'sk-vue-column--droptarget': dragOverColumn === column }"
			:draggable="!readOnly && renamingColumn !== column"
			@dragstart="onColumnDragStart($event, column)"
			@dragend="dragOverColumn = null"
			@dragenter.prevent="dragOverColumn = column"
			@dragover.prevent
			@drop="onDrop($event, column)">
			<header class="sk-vue-column-head">
				<span v-if="!readOnly" class="sk-vue-col-grip" :title="t('Glisser la colonne pour la déplacer')" aria-hidden="true">⠿</span>
				<input
					v-if="renamingColumn === column"
					ref="renameInput"
					v-model="renameValue"
					class="sk-vue-column-rename"
					type="text"
					@keyup.enter="confirmRenameColumn(column)"
					@blur="cancelRenameColumn">
				<span v-else class="sk-vue-column-name">{{ column }}</span>
				<span class="sk-vue-count">{{ (cardsByColumn[column] || []).length }}</span>
				<span v-if="!readOnly" class="sk-vue-col-actions">
					<NcButton
						type="tertiary"
						:aria-label="t('Renommer la liste')"
						:title="t('Renommer la liste')"
						@click="startRenameColumn(column)">
						✎
					</NcButton>
					<NcButton
						type="tertiary"
						:aria-label="t('Supprimer la liste')"
						:title="t('Supprimer la liste')"
						@click="$emit('remove-column', column)">
						✕
					</NcButton>
				</span>
			</header>

			<div class="sk-vue-column-cards">
			<article
				v-for="card in cardsByColumn[column] || []"
				:key="card.id"
				class="sk-vue-card"
				:class="{ 'sk-vue-card--done': card.completed_at }"
				:draggable="!readOnly"
				@dragstart.stop="onDragStart($event, card)"
				@click="$emit('open', card)">
				<div class="sk-vue-card-title">
					<span v-if="card.completed_at" class="sk-done-check" :title="t('Terminée')">✓ </span>{{ card.title }}
				</div>
				<div v-if="card.excerpt" class="sk-vue-card-excerpt">{{ card.excerpt }}</div>
				<div v-if="cardMeta(card).length" class="sk-vue-card-meta">
					<span v-for="(m, i) in cardMeta(card)" :key="i" class="sk-vue-chip">{{ m }}</span>
				</div>
				<div v-if="(card.assignees || []).length" class="sk-vue-card-avatars">
					<NcAvatar
						v-for="a in card.assignees"
						:key="a"
						:user="a"
						:size="24"
						:show-user-status="false" />
				</div>
			</article>
			</div>

			<input
				v-if="!readOnly && addingColumn === column"
				ref="newInput"
				v-model="newTitle"
				class="sk-vue-newcard"
				type="text"
				:placeholder="t('Titre de la carte')"
				@keyup.enter="confirmAdd(column)"
				@blur="cancelAdd">
			<div v-else-if="!readOnly" class="sk-vue-colfooter">
				<NcButton
					type="tertiary"
					class="sk-vue-addcard"
					@click="startAdd(column)">
					{{ t('+ Carte') }}
				</NcButton>
				<NcActions
					v-if="templates.length"
					:aria-label="t('Nouvelle carte depuis un gabarit')"
					:title="t('Nouvelle carte depuis un gabarit')">
					<template #icon>
						<span aria-hidden="true">📋</span>
					</template>
					<NcActionButton
						v-for="tpl in templates"
						:key="tpl.name"
						@click="$emit('add-from-template', { column, template: tpl })">
						<template #icon>
							<span aria-hidden="true">📋</span>
						</template>
						{{ tpl.name }}
					</NcActionButton>
				</NcActions>
			</div>
		</section>

		<section v-if="!readOnly" class="sk-vue-addlist">
			<input
				v-if="addingList"
				ref="newListInput"
				v-model="newListName"
				class="sk-vue-newcard"
				type="text"
				:placeholder="t('Nom de la liste')"
				@keyup.enter="confirmAddList"
				@blur="cancelAddList">
			<NcButton
				v-else
				type="tertiary"
				class="sk-vue-addcard"
				@click="startAddList">
				{{ t('+ Liste') }}
			</NcButton>
		</section>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { prioLabel } from '../priority.js'

export default {
	name: 'BoardView',

	components: { NcButton, NcActions, NcActionButton, NcAvatar },

	props: {
		board: { type: Object, required: true },
		cardsByColumn: { type: Object, default: () => ({}) },
		readOnly: { type: Boolean, default: false },
		templates: { type: Array, default: () => [] },
	},

	emits: ['open', 'add-card', 'move-card', 'add-from-template', 'add-column', 'rename-column', 'remove-column', 'reorder-column'],

	data() {
		return {
			addingColumn: null,
			newTitle: '',
			addingList: false,
			newListName: '',
			renamingColumn: null,
			renameValue: '',
			dragOverColumn: null,
		}
	},

	methods: {
		t(s) {
			return s
		},

		startRenameColumn(column) {
			this.renamingColumn = column
			this.renameValue = column
			this.$nextTick(() => {
				if (this.$refs.renameInput) {
					this.$refs.renameInput.focus()
				}
			})
		},

		confirmRenameColumn(from) {
			const to = this.renameValue.trim()
			this.renamingColumn = null
			this.renameValue = ''
			if (to && to !== from) {
				this.$emit('rename-column', { from, to })
			}
		},

		cancelRenameColumn() {
			this.renamingColumn = null
			this.renameValue = ''
		},

		startAddList() {
			this.addingList = true
			this.newListName = ''
			this.$nextTick(() => {
				if (this.$refs.newListInput) {
					this.$refs.newListInput.focus()
				}
			})
		},

		confirmAddList() {
			const name = this.newListName.trim()
			this.addingList = false
			this.newListName = ''
			if (name) {
				this.$emit('add-column', name)
			}
		},

		cancelAddList() {
			this.addingList = false
			this.newListName = ''
		},

		startAdd(column) {
			this.addingColumn = column
			this.newTitle = ''
			this.$nextTick(() => {
				if (this.$refs.newInput) {
					this.$refs.newInput.focus()
				}
			})
		},

		confirmAdd(column) {
			const title = this.newTitle.trim()
			this.addingColumn = null
			this.newTitle = ''
			if (title) {
				this.$emit('add-card', { column, title })
			}
		},

		cancelAdd() {
			this.addingColumn = null
			this.newTitle = ''
		},

		// One short chip line per card, mirroring the vanilla tile meta.
		cardMeta(card) {
			const out = []
			if (card.checklist && card.checklist.total > 0) {
				out.push('☑ ' + card.checklist.done + '/' + card.checklist.total)
			}
			if (card.comment_count > 0) {
				out.push('💬 ' + card.comment_count)
			}
			if (card.due_date) {
				out.push('📅 ' + String(card.due_date).replace('T', ' '))
			}
			;(card.tags || []).forEach((tag) => out.push('🏷 ' + tag))
			if (card.priority) {
				out.push(prioLabel(card.priority))
			}
			return out
		},

		onDragStart(e, card) {
			e.dataTransfer.setData('text/plain', card.id)
		},

		// Dragging a column by its header reorders columns (Alain, 2026-07-18:
		// drag the columns themselves on the board). A distinct data type keeps it
		// separate from card drags.
		onColumnDragStart(e, column) {
			e.dataTransfer.setData('application/x-sk-column', column)
		},

		onDrop(e, column) {
			if (this.readOnly) {
				return
			}
			const draggedColumn = e.dataTransfer.getData('application/x-sk-column')
			if (draggedColumn) {
				if (draggedColumn !== column) {
					this.$emit('reorder-column', { from: draggedColumn, to: column })
				}
				return
			}
			const cardId = e.dataTransfer.getData('text/plain')
			if (cardId) {
				this.$emit('move-card', { cardId, column })
			}
		},
	},
}
</script>

<style scoped>
.sk-vue-columns {
	display: flex;
	gap: 16px;
	padding: 16px 24px;
	align-items: stretch;
	/* Columns that overflow scroll horizontally — they must NOT wrap to the next
	   line (Alain, 2026-07-18). nowrap + overflow-x:auto gives the scroll bar. */
	flex-wrap: nowrap;
	overflow-x: auto;
	/* Fill the board's height and keep the horizontal scrollbar at the bottom of
	   the viewport (Alain, 2026-07-19: it sat below the fold). Cards scroll inside
	   each column, not the whole area. */
	overflow-y: hidden;
	flex: 1 1 auto;
	min-height: 0;
}

.sk-readonly-banner {
	flex: 0 0 100%;
	padding: 8px 14px;
	border-radius: var(--border-radius, 8px);
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-element-text, #fff);
	font-weight: 500;
}

.sk-vue-column {
	flex: 0 0 280px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 12px);
	padding: 8px;
	display: flex;
	flex-direction: column;
	max-height: 100%;
	min-height: 0;
}

/* The cards scroll here; the header and the ＋ Carte footer stay put. */
.sk-vue-column-cards {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
}

.sk-vue-column--droptarget {
	outline: 2px dashed var(--color-primary-element);
	outline-offset: -2px;
}

.sk-vue-column-rename {
	flex: 1 1 auto;
	min-width: 0;
	font-weight: 600;
}

.sk-vue-column-head[draggable="true"] {
	cursor: grab;
}

.sk-vue-col-grip {
	color: var(--color-text-maxcontrast);
	cursor: grab;
}

.sk-vue-col-actions {
	display: flex;
	gap: 2px;
}

.sk-vue-col-actions :deep(.button-vue) {
	min-height: 30px !important;
	min-width: 30px !important;
	height: 30px;
}

.sk-vue-column-head {
	display: flex;
	align-items: center;
	gap: 6px;
	justify-content: space-between;
	padding: 4px 8px 8px;
	font-weight: 600;
}

.sk-vue-count {
	color: var(--color-text-maxcontrast);
}

.sk-vue-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	padding: 8px 10px;
	margin-bottom: 8px;
	cursor: pointer;
}

.sk-vue-card-title {
	font-weight: 500;
}

.sk-vue-card--done {
	opacity: 0.6;
}

.sk-vue-card--done .sk-vue-card-title {
	text-decoration: line-through;
}

.sk-done-check {
	color: var(--color-success, #46ba61);
	text-decoration: none;
	font-weight: 700;
}

.sk-vue-card-excerpt {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
	margin-top: 2px;
}

.sk-vue-card-meta {
	margin-top: 6px;
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.sk-vue-card-avatars {
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
	margin-top: 6px;
}

.sk-vue-chip {
	font-size: 85%;
	background: var(--color-background-dark);
	border-radius: 6px;
	padding: 1px 6px;
}

.sk-vue-addlist {
	flex: 0 0 200px;
	padding: 8px;
}

.sk-vue-colfooter {
	display: flex;
	align-items: center;
	gap: 4px;
}

.sk-vue-addcard {
	flex: 1 1 auto;
}
</style>
