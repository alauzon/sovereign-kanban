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
	<div class="sk-vue-columns" :class="{ 'sk-vue-columns--compact': compact }">
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
					@keyup.esc="cancelRenameColumn"
					@blur="confirmRenameColumn(column)">
				<span
					v-else
					class="sk-vue-column-name"
					:class="{ 'sk-vue-column-name--editable': !readOnly }"
					:title="!readOnly ? t('Cliquer pour renommer la liste') : ''"
					@click="!readOnly && startRenameColumn(column)">{{ column }}</span>
				<span class="sk-vue-count">{{ (cardsByColumn[column] || []).length }}</span>
				<span v-if="!readOnly" class="sk-vue-col-actions">
					<NcButton
						type="tertiary"
						:aria-label="t('Menu de la liste')"
						:title="t('Menu de la liste')"
						@click="toggleColumnMenu($event, column)">
						⋯
					</NcButton>
				</span>
			</header>

			<div class="sk-vue-column-cards">
			<article
				v-for="card in cardsByColumn[column] || []"
				:key="card.id"
				class="sk-vue-card"
				:class="{ 'sk-vue-card--done': card.completed_at, 'sk-vue-card--archived': card.archived }"
				:style="card.color ? { borderLeft: '4px solid ' + card.color } : {}"
				:draggable="!readOnly"
				@dragstart.stop="onDragStart($event, card)"
				@click="$emit('open', card)">
				<div v-if="!readOnly" class="sk-vue-card-quick">
					<button
						type="button"
						:aria-label="t('Menu de la carte')"
						:title="t('Menu de la carte')"
						@click.stop="toggleCardMenu($event, card)">
						⋯
					</button>
				</div>
				<img
					v-if="showCovers && card.cover"
					class="sk-vue-card-cover"
					:src="coverUrl(card)"
					alt=""
					loading="lazy">
				<div class="sk-vue-card-title">
					<input
						v-if="renamingCard === card.id"
						ref="cardRenameInput"
						v-model="cardRenameValue"
						class="sk-vue-card-rename"
						type="text"
						@click.stop
						@keyup.enter="confirmRenameCard(card)"
						@keyup.esc="cancelRenameCard"
						@blur="confirmRenameCard(card)">
					<template v-else>
						<span v-if="card.completed_at" class="sk-done-check" :title="t('Terminée')">✓ </span>{{ card.title }}
					</template>
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

		<!-- Column menu, teleported to the body and positioned fixed: a plain
		     dropdown inside .sk-vue-columns would be clipped by its overflow, the
		     same reason NcActions fails here. -->
		<Teleport to="body">
			<div v-if="menuColumn !== null" class="sk-col-menu-backdrop" @click="menuColumn = null" />
			<div v-if="menuColumn !== null" class="sk-col-menu" :style="menuStyle">
				<button type="button" class="sk-col-menu-item" @click="markColumnDone(menuColumn)">
					✓ {{ t('Définir les cartes comme « terminées »') }}
				</button>
				<button type="button" class="sk-col-menu-item" @click="archiveColumnFromMenu(menuColumn)">
					📦 {{ t('Archiver toutes les cartes') }}
				</button>
				<button type="button" class="sk-col-menu-item sk-col-menu-danger" @click="removeColumnFromMenu(menuColumn)">
					🗑 {{ t('Supprimer la liste') }}
				</button>
			</div>
		</Teleport>

		<!-- Card ⋯ menu (Alain, 2026-07-19): Détails + Modifier le titre for now;
		     couleur / m'affecter / déplacer / archiver come with those features. -->
		<Teleport to="body">
			<div v-if="menuCard !== null" class="sk-col-menu-backdrop" @click="menuCard = null" />
			<div v-if="menuCard !== null" class="sk-col-menu" :style="cardMenuStyle">
				<div v-if="paletteColors.length" class="sk-card-swatches">
					<button
						v-for="c in paletteColors"
						:key="c"
						type="button"
						class="sk-swatch"
						:class="{ 'sk-swatch--on': menuCard.color === c }"
						:style="{ background: c }"
						:aria-label="t('Couleur') + ' ' + c"
						:title="c"
						@click="setCardColor(menuCard, c)" />
					<button
						type="button"
						class="sk-swatch sk-swatch-none"
						:aria-label="t('Aucune couleur')"
						:title="t('Aucune couleur')"
						@click="setCardColor(menuCard, '')">
						✕
					</button>
				</div>
				<button type="button" class="sk-col-menu-item" @click="cardMenuOpen(menuCard)">
					🔍 {{ t('Détails de la carte') }}
				</button>
				<button type="button" class="sk-col-menu-item" @click="cardMenuRename(menuCard)">
					✎ {{ t('Modifier le titre') }}
				</button>
				<button type="button" class="sk-col-menu-item" @click="cardMenuToggleDone(menuCard)">
					{{ menuCard.completed_at ? '↺ ' + t('Rouvrir') : '✓ ' + t('Marquer comme fait') }}
				</button>
				<button type="button" class="sk-col-menu-item" @click="cardMenuArchive(menuCard)">
					{{ menuCard.archived ? '📤 ' + t('Désarchiver la carte') : '📦 ' + t('Archiver la carte') }}
				</button>
				<button type="button" class="sk-col-menu-item sk-col-menu-danger" @click="cardMenuDelete(menuCard)">
					🗑 {{ t('Supprimer la carte') }}
				</button>
			</div>
		</Teleport>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { generateUrl } from '@nextcloud/router'
import { prioLabel } from '../priority.js'

export default {
	name: 'BoardView',

	components: { NcButton, NcActions, NcActionButton, NcAvatar },

	props: {
		board: { type: Object, required: true },
		cardsByColumn: { type: Object, default: () => ({}) },
		readOnly: { type: Boolean, default: false },
		templates: { type: Array, default: () => [] },
		compact: { type: Boolean, default: false },
		showCovers: { type: Boolean, default: false },
	},

	emits: ['open', 'add-card', 'move-card', 'add-from-template', 'add-column', 'rename-column', 'remove-column', 'reorder-column', 'toggle-done', 'delete-card', 'mark-column-done', 'rename-card', 'set-card-color', 'archive-card', 'archive-column'],

	data() {
		return {
			addingColumn: null,
			newTitle: '',
			addingList: false,
			newListName: '',
			renamingColumn: null,
			renameValue: '',
			dragOverColumn: null,
			menuColumn: null,
			menuStyle: {},
			menuCard: null,
			cardMenuStyle: {},
			renamingCard: null,
			cardRenameValue: '',
		}
	},

	computed: {
		// Distinct colours from the board's tag palette, for the card colour
		// swatches (Alain, 2026-07-19: card colour = the board palette).
		paletteColors() {
			const cols = ((this.board && this.board.tags) || []).map((t) => t.color).filter(Boolean)
			return [...new Set(cols)]
		},
	},

	methods: {
		t(s) {
			return s
		},

		// URL of a card's cover image (its first image attachment). Same session
		// cookie authorises it, so a plain <img src> works.
		coverUrl(card) {
			const boardId = (this.board && this.board.id) || ''
			return generateUrl(
				'/apps/sovereign-kanban-md-persistence/api/v1/boards/'
				+ encodeURIComponent(boardId) + '/cards/' + encodeURIComponent(card.id)
				+ '/attachments/' + encodeURIComponent(card.cover),
			)
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

		// Column ⋯ menu. Anchored fixed under the button (teleported to body) so
		// the overflow of .sk-vue-columns cannot clip it.
		toggleColumnMenu(ev, column) {
			if (this.menuColumn === column) {
				this.menuColumn = null
				return
			}
			const rect = ev.currentTarget.getBoundingClientRect()
			this.menuStyle = {
				top: (rect.bottom + 4) + 'px',
				left: Math.max(8, rect.right - 260) + 'px',
			}
			this.menuColumn = column
		},

		markColumnDone(column) {
			this.$emit('mark-column-done', column)
			this.menuColumn = null
		},

		removeColumnFromMenu(column) {
			this.$emit('remove-column', column)
			this.menuColumn = null
		},

		// Card ⋯ menu — same teleported-fixed pattern as the column menu.
		toggleCardMenu(ev, card) {
			if (this.menuCard && this.menuCard.id === card.id) {
				this.menuCard = null
				return
			}
			const rect = ev.currentTarget.getBoundingClientRect()
			this.cardMenuStyle = {
				top: (rect.bottom + 4) + 'px',
				left: Math.max(8, rect.right - 220) + 'px',
			}
			this.menuCard = card
		},

		cardMenuOpen(card) {
			this.$emit('open', card)
			this.menuCard = null
		},

		setCardColor(card, color) {
			this.$emit('set-card-color', { card, color })
			this.menuCard = null
		},

		cardMenuToggleDone(card) {
			this.$emit('toggle-done', card)
			this.menuCard = null
		},

		cardMenuArchive(card) {
			this.$emit('archive-card', card)
			this.menuCard = null
		},

		cardMenuDelete(card) {
			this.$emit('delete-card', card)
			this.menuCard = null
		},

		archiveColumnFromMenu(column) {
			this.$emit('archive-column', column)
			this.menuColumn = null
		},

		cardMenuRename(card) {
			this.menuCard = null
			this.startRenameCard(card)
		},

		startRenameCard(card) {
			this.renamingCard = card.id
			this.cardRenameValue = card.title
			this.$nextTick(() => {
				if (this.$refs.cardRenameInput) {
					this.$refs.cardRenameInput.focus()
					this.$refs.cardRenameInput.select()
				}
			})
		},

		confirmRenameCard(card) {
			const to = this.cardRenameValue.trim()
			this.renamingCard = null
			this.cardRenameValue = ''
			if (to && to !== card.title) {
				this.$emit('rename-card', { card, title: to })
			}
		},

		cancelRenameCard() {
			this.renamingCard = null
			this.cardRenameValue = ''
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

/* The list name is clickable to rename (Alain, 2026-07-19: click the name, no
   pencil). A subtle hover hints at it. */
.sk-vue-column-name--editable {
	cursor: text;
	border-radius: 4px;
	padding: 0 4px;
}

.sk-vue-column-name--editable:hover {
	background: var(--color-background-hover);
}

.sk-vue-col-actions {
	display: flex;
	gap: 2px;
}

/* Column ⋯ menu, teleported to body (fixed) so overflow cannot clip it. */
.sk-col-menu-backdrop {
	position: fixed;
	inset: 0;
	z-index: 10000;
}

.sk-col-menu {
	position: fixed;
	z-index: 10001;
	min-width: 260px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	box-shadow: 0 2px 12px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
	padding: 4px;
	display: flex;
	flex-direction: column;
}

.sk-col-menu-item {
	display: block;
	width: 100%;
	text-align: left;
	background: none;
	border: none;
	border-radius: 6px;
	padding: 8px 10px;
	cursor: pointer;
	color: var(--color-main-text);
	white-space: nowrap;
}

.sk-col-menu-item:hover:not([disabled]) {
	background: var(--color-background-hover);
}

.sk-col-menu-item[disabled] {
	opacity: 0.5;
	cursor: default;
}

.sk-col-menu-danger {
	color: var(--color-error, #e9322d);
}

.sk-vue-card-rename {
	width: 100%;
	box-sizing: border-box;
	font: inherit;
}

/* Colour swatches at the top of the card ⋯ menu. */
.sk-card-swatches {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 6px 8px 8px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 4px;
}

.sk-swatch {
	width: 22px;
	height: 22px;
	border-radius: 50%;
	border: 2px solid transparent;
	cursor: pointer;
	padding: 0;
}

.sk-swatch--on {
	border-color: var(--color-main-text);
}

.sk-swatch-none {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
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
	position: relative;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	padding: 8px 10px;
	margin-bottom: 8px;
	cursor: pointer;
	overflow: hidden;
}

/* Cover image (first image attachment), shown when « Images de couverture » is on.
   Full-bleed at the top of the tile. */
.sk-vue-card-cover {
	display: block;
	width: calc(100% + 20px);
	margin: -8px -10px 8px;
	max-height: 140px;
	object-fit: cover;
}

/* Compact display (Alain, 2026-07-19): denser tiles — drop the excerpt, tighten
   spacing, cap the cover shorter. */
.sk-vue-columns--compact .sk-vue-card {
	padding: 5px 8px;
	margin-bottom: 5px;
}

.sk-vue-columns--compact .sk-vue-card-excerpt {
	display: none;
}

.sk-vue-columns--compact .sk-vue-card-cover {
	max-height: 72px;
	margin: -5px -8px 5px;
	width: calc(100% + 16px);
}

/* The ⋯ menu anchor stays visible at rest (Alain, 2026-07-19), a touch muted,
   and full-strength on hover/focus. */
.sk-vue-card-quick {
	position: absolute;
	top: 4px;
	right: 4px;
	display: flex;
	gap: 2px;
	opacity: 0.55;
	transition: opacity 0.1s;
}

.sk-vue-card:hover .sk-vue-card-quick,
.sk-vue-card:focus-within .sk-vue-card-quick {
	opacity: 1;
}

.sk-vue-card-quick button {
	width: 22px;
	height: 22px;
	line-height: 1;
	font-size: 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	cursor: pointer;
}

.sk-vue-card-quick button:hover {
	background: var(--color-background-hover);
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

/* Archived card, only shown when « Afficher les archivées » is on: muted with a
   dashed outline so it reads as put-away, not active. */
.sk-vue-card--archived {
	opacity: 0.5;
	border-style: dashed;
	border-color: var(--color-border);
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
