<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - Phase 2 shell + content migration. NcAppNavigation lists the boards;
  - BoardView renders the selected board's columns and cards. Both read the same
  - REST API as the vanilla app. Gestures migrate here behind the characterization
  - test (Kate's gate); the default page load stays vanilla until parity.
-->
<template>
	<NcContent app-name="sovereign-kanban">
		<NcAppNavigation>
			<NcAppNavigationNew :text="t('Nouveau tableau')" @click="openBoardCreate">
				<template #icon>
					<span aria-hidden="true">+</span>
				</template>
			</NcAppNavigationNew>
			<template #list>
				<NcAppNavigationItem
					v-for="board in boards"
					:key="board.id"
					:name="board.name"
					:active="board.id === currentId"
					@click="select(board.id)">
					<template #icon>
						<span class="sk-nav-dot" :style="{ background: board.color || '#888' }" />
					</template>
					<template v-if="board.shared" #counter>
						<span :title="sharedTitle(board)">👥</span>
					</template>
					<template v-if="!board.shared" #actions>
						<NcActionButton :aria-label="t('Éditer le tableau')" @click="openBoardEdit(board)">
							<template #icon>
								<span aria-hidden="true">✎</span>
							</template>
							{{ t('Éditer') }}
						</NcActionButton>
						<NcActionButton :aria-label="t('Supprimer le tableau')" @click="deleteBoard(board)">
							<template #icon>
								<span aria-hidden="true">🗑</span>
							</template>
							{{ t('Supprimer') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<NcEmptyContent v-if="loading" :name="t('Chargement…')">
				<template #icon>
					<NcLoadingIcon :size="32" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent
				v-else-if="!currentBoard"
				:name="t('Sovereign Kanban')"
				:description="t('Choisissez un tableau dans la navigation.')" />
			<template v-else>
				<div class="sk-vue-board">
				<div class="sk-vue-board-header">
					<h2 class="sk-vue-board-title">{{ currentBoard.name }}</h2>
					<div class="sk-vue-toolbar">
						<NcButton
							type="tertiary"
							:class="{ 'sk-toolbtn--on': filtersOpen || activeFilterCount }"
							:aria-label="t('Filtres')"
							:title="t('Filtres')"
							@click="filtersOpen = !filtersOpen">
							<span aria-hidden="true">⧩</span> {{ t('Filtres') }}{{ activeFilterCount ? ' (' + activeFilterCount + ')' : '' }}
						</NcButton>
					</div>
				</div>
				<FilterBar
					v-if="filtersOpen"
					:dimensions="filterDimensions"
					:selected="filters"
					@toggle="toggleFilter"
					@reset="resetFilters"
					@close="filtersOpen = false" />
				<BoardView
					:board="currentBoard"
					:cards-by-column="filteredCardsByColumn"
					:read-only="readOnly"
					:templates="templates"
					@open="openCard"
					@add-card="addCard"
					@move-card="moveCard"
					@add-from-template="addCardFromTemplate"
					@add-column="addColumn"
					@rename-column="renameColumn"
					@remove-column="removeColumn"
					@reorder-column="reorderColumn" />
				</div>
			</template>

			<CardDetail
				v-if="openedCard"
				:board-id="currentId"
				:card="openedCard"
				:read-only="readOnly"
				:known-tags="knownTags"
				:palette="(currentBoard && currentBoard.tags) || []"
				@saved="onCardSaved"
				@deleted="onCardDeleted"
				@close="openedCard = null" />

			<BoardEditModal
				v-if="boardEditorOpen"
				:board="boardEditorTarget"
				@saved="onBoardSaved"
				@deleted="onBoardDeleted"
				@refresh="onBoardRefresh"
				@close="boardEditorOpen = false" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import BoardView from './components/BoardView.vue'
import CardDetail from './components/CardDetail.vue'
import BoardEditModal from './components/BoardEditModal.vue'
import FilterBar from './components/FilterBar.vue'
import { prioLabel } from './priority.js'

const BOARDS = '/apps/sovereign-kanban-md-persistence/api/v1/boards'

export default {
	name: 'App',

	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationNew,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActionButton,
		NcButton,
		BoardView,
		CardDetail,
		BoardEditModal,
		FilterBar,
	},

	data() {
		return {
			boards: [],
			currentId: null,
			cardsByColumn: {},
			openedCard: null,
			loading: true,
			boardEditorOpen: false,
			boardEditorTarget: null,
			templates: [],
			filtersOpen: false,
			filters: { tags: [], assignees: [], phases: [], priorities: [], status: [] },
		}
	},

	computed: {
		currentBoard() {
			return this.boards.find((b) => b.id === this.currentId) || null
		},

		// A shared board without the UPDATE bit (2) is read-only — same rule and
		// the same bug fix as the vanilla app (Steve's bare-403, 2026-07-18).
		readOnly() {
			const b = this.currentBoard
			return !!(b && b.shared && !((b.permissions || 0) & 2))
		},

		// Every distinct tag used across the board's cards, sorted — fed to the
		// card editor so it can suggest existing tags (Alain, 2026-07-18).
		knownTags() {
			const seen = new Set()
			Object.values(this.cardsByColumn).forEach((cards) => {
				(cards || []).forEach((card) => {
					(card.tags || []).forEach((tag) => seen.add(tag))
				})
			})
			return [...seen].sort((a, b) => a.localeCompare(b))
		},

		allCards() {
			return Object.values(this.cardsByColumn).flat()
		},

		// Filter dimensions with the values actually present on the board.
		filterDimensions() {
			const cards = this.allCards
			const uniq = (arr) => [...new Set(arr)]
			const tags = uniq(cards.flatMap((c) => c.tags || [])).sort((a, b) => a.localeCompare(b))
			const assignees = uniq(cards.flatMap((c) => c.assignees || [])).sort((a, b) => a.localeCompare(b))
			const phases = uniq(cards.map((c) => c.phase).filter((p) => p != null).map(String)).sort()
			const priorities = uniq(cards.map((c) => c.priority).filter((p) => p != null && p !== '').map(String)).sort()
			return [
				{ key: 'tags', label: this.t('Étiquettes'), options: tags.map((v) => ({ value: v, label: v, style: this.filterTagStyle(v) })) },
				{ key: 'assignees', label: this.t('Assignés'), options: assignees.map((v) => ({ value: v, label: v })) },
				{ key: 'phases', label: this.t('Phase'), options: phases.map((v) => ({ value: v, label: this.t('Phase') + ' ' + v })) },
				{ key: 'priorities', label: this.t('Priorité'), options: priorities.map((v) => ({ value: v, label: prioLabel(v) })) },
				{ key: 'status', label: this.t('Statut'), options: [{ value: 'done', label: this.t('✓ Terminée') }, { value: 'open', label: this.t('À faire') }] },
			]
		},

		activeFilterCount() {
			return Object.values(this.filters).reduce((n, arr) => n + (arr ? arr.length : 0), 0)
		},

		// OR within a dimension, AND between dimensions.
		filteredCardsByColumn() {
			if (!this.activeFilterCount) {
				return this.cardsByColumn
			}
			const f = this.filters
			const out = {}
			for (const [col, cards] of Object.entries(this.cardsByColumn)) {
				out[col] = (cards || []).filter((card) => this.matchesFilters(card, f))
			}
			return out
		},
	},

	async mounted() {
		await this.loadBoards()
		this.loadTemplates()
	},

	methods: {
		t(s) {
			return s
		},

		sharedTitle(board) {
			return this.t('Partagé avec vous') + (board.owner ? ' — ' + board.owner : '')
		},

		url(path) {
			return generateUrl('/apps/sovereign-kanban-md-persistence/api/v1' + path)
		},

		async loadBoards() {
			this.loading = true
			try {
				const res = await axios.get(generateUrl(BOARDS))
				this.boards = res.data.boards || []
				if (!this.currentId && this.boards.length) {
					await this.select(this.boards[0].id)
				}
			} catch (e) {
				this.boards = []
			} finally {
				this.loading = false
			}
		},

		async select(id) {
			this.currentId = id
			this.openedCard = null
			this.loadFilters()
			await this.loadCards()
		},

		async loadCards() {
			if (!this.currentId) {
				return
			}
			try {
				const res = await axios.get(this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards'))
				this.cardsByColumn = res.data.cards || {}
			} catch (e) {
				this.cardsByColumn = {}
			}
		},

		async addCard({ column, title }) {
			const res = await axios.post(this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards'), {
				title,
				column,
			})
			// Default priority 3 (Alain, 2026-07-18). The create endpoint doesn't
			// take a priority, so set it with a follow-up update, preserving the
			// rest of the new card.
			const c = res.data && res.data.card
			if (c && c.id) {
				try {
					await axios.put(
						this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(c.id)),
						{
							title: c.title,
							description: c.description || '',
							start_date: c.start_date || '',
							due_date: c.due_date || '',
							assignees: c.assignees || [],
							priority: '3',
							tags: c.tags || [],
							phase: c.phase != null ? String(c.phase) : '',
						},
					)
				} catch (e) {
					// Best effort: the card exists even if the priority didn't stick.
				}
			}
			await this.loadCards()
		},

		// Add a list/column inline from the board (Alain, 2026-07-18). The columns
		// live in .board.yml, so reload the board list too, then the cards.
		async addColumn(name) {
			try {
				await axios.post(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/columns'),
					{ name },
				)
				await this.loadBoards()
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible d\'ajouter la liste (nom déjà pris ?).'))
			}
		},

		async renameColumn({ from, to }) {
			try {
				await axios.put(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/columns/rename'),
					{ from, to },
				)
				await this.loadBoards()
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible de renommer la liste (nom déjà pris ?).'))
			}
		},

		// Reorder columns by dropping one header on another (Alain, 2026-07-18).
		async reorderColumn({ from, to }) {
			const cols = [...((this.currentBoard && this.currentBoard.columns) || [])]
			const fromIdx = cols.indexOf(from)
			const toIdx = cols.indexOf(to)
			if (fromIdx === -1 || toIdx === -1 || fromIdx === toIdx) {
				return
			}
			cols.splice(fromIdx, 1)
			cols.splice(toIdx, 0, from)
			try {
				await axios.put(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/columns/reorder'),
					{ columns: cols },
				)
				await this.loadBoards()
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible de réordonner les listes.'))
			}
		},

		async removeColumn(name) {
			// eslint-disable-next-line no-alert
			if (!window.confirm(this.t('Supprimer la liste « ') + name + this.t(' » et ses cartes ?'))) {
				return
			}
			try {
				await axios.delete(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/columns'),
					{ data: { name } },
				)
				await this.loadBoards()
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible de supprimer la liste.'))
			}
		},

		async loadTemplates() {
			try {
				const res = await axios.get(generateUrl('/apps/sovereign-kanban-md-persistence/api/v1/templates'))
				this.templates = res.data.templates || []
			} catch (e) {
				this.templates = []
			}
		},

		// Create a card from a template in a specific column (the button lives in
		// each column footer — Alain, 2026-07-18: clearer than a single top-right
		// menu, and it makes the target column explicit). Carries the template's
		// body and procedures.
		async addCardFromTemplate({ column, template }) {
			// eslint-disable-next-line no-alert
			const title = window.prompt(this.t('Titre de la carte'), template.name)
			if (title === null) {
				return
			}
			try {
				await axios.post(this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards'), {
					title: title.trim() || template.name,
					column,
					description: template.body,
					procedures: (template.meta && template.meta['procédures']) ? template.meta['procédures'] : [],
				})
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Erreur à la création de la carte depuis le gabarit.'))
			}
		},

		async moveCard({ cardId, column }) {
			await axios.put(
				this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(cardId) + '/move'),
				{ toColumn: column },
			)
			await this.loadCards()
		},

		async openCard(card) {
			// Fetch the full card (the list carries an excerpt, not the body). The
			// list item already carries created_at (the detail endpoint doesn't), so
			// merge it in for the card's summary line — no backend round-trip.
			const res = await axios.get(
				this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(card.id)),
			)
			this.openedCard = { created_at: card.created_at, ...res.data.card }
		},

		async onCardSaved() {
			this.openedCard = null
			await this.loadCards()
		},

		async onCardDeleted() {
			this.openedCard = null
			await this.loadCards()
		},

		openBoardCreate() {
			this.boardEditorTarget = null
			this.boardEditorOpen = true
		},

		async openBoardEdit(board) {
			// Select first, so a live column edit reloads this board's cards.
			if (board.id !== this.currentId) {
				await this.select(board.id)
			}
			this.boardEditorTarget = this.currentBoard || board
			this.boardEditorOpen = true
		},

		// Delete straight from the navigation action menu (with confirmation).
		async deleteBoard(board) {
			if (!window.confirm(this.t('Supprimer le tableau « ') + board.name + this.t(' » et son contenu ?'))) {
				return
			}
			await axios.delete(this.url('/boards/' + encodeURIComponent(board.id)))
			await this.onBoardDeleted()
		},

		async onBoardSaved(newId) {
			this.boardEditorOpen = false
			await this.loadBoards()
			if (newId) {
				await this.select(newId)
			}
		},

		async onBoardDeleted() {
			this.boardEditorOpen = false
			await this.loadBoards()
			// If the current board was the one removed, fall back to the first.
			if (!this.boards.some((b) => b.id === this.currentId)) {
				this.currentId = null
				if (this.boards.length) {
					await this.select(this.boards[0].id)
				} else {
					this.cardsByColumn = {}
				}
			}
		},

		// A column changed inside the editor: reload the board list (columns) and
		// the current board's cards without closing the modal.
		async onBoardRefresh() {
			await this.loadBoards()
			await this.loadCards()
		},

		// --- Filters (OR within a dimension, AND between dimensions) ---

		matchesFilters(card, f) {
			if (f.tags.length && !f.tags.some((t) => (card.tags || []).includes(t))) {
				return false
			}
			if (f.assignees.length && !f.assignees.some((a) => (card.assignees || []).includes(a))) {
				return false
			}
			if (f.phases.length && !f.phases.includes(String(card.phase))) {
				return false
			}
			if (f.priorities.length && !f.priorities.includes(String(card.priority))) {
				return false
			}
			if (f.status && f.status.length) {
				const done = !!card.completed_at
				if (done && !f.status.includes('done')) {
					return false
				}
				if (!done && !f.status.includes('open')) {
					return false
				}
			}
			return true
		},

		filterTagStyle(name) {
			const board = this.currentBoard
			const found = board && (board.tags || []).find((t) => t.name === name)
			if (found && found.color) {
				return { background: found.color, color: '#fff', borderColor: found.color }
			}
			return {}
		},

		toggleFilter(key, value) {
			const arr = this.filters[key] || []
			const i = arr.indexOf(value)
			if (i === -1) {
				arr.push(value)
			} else {
				arr.splice(i, 1)
			}
			this.filters = { ...this.filters, [key]: arr }
			this.saveFilters()
		},

		resetFilters() {
			this.filters = { tags: [], assignees: [], phases: [], priorities: [], status: [] }
			this.saveFilters()
		},

		filtersKey() {
			return 'sk-filters-' + this.currentId
		},

		// Filters persist per board across navigation (Alain, 2026-07-18).
		loadFilters() {
			const empty = { tags: [], assignees: [], phases: [], priorities: [], status: [] }
			try {
				const raw = window.localStorage.getItem(this.filtersKey())
				this.filters = raw ? { ...empty, ...JSON.parse(raw) } : empty
			} catch (e) {
				this.filters = empty
			}
		},

		saveFilters() {
			try {
				window.localStorage.setItem(this.filtersKey(), JSON.stringify(this.filters))
			} catch (e) {
				// storage unavailable — filters just won't persist
			}
		},
	},
}
</script>

<style scoped>
.sk-nav-dot {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 50%;
}

/* Full-height board so the horizontal scrollbar sits at the bottom of the
   viewport (Alain, 2026-07-19: it was hidden below the fold), and each column
   scrolls its own cards. */
.sk-vue-board {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
}

.sk-vue-board-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding-right: 16px;
	flex: 0 0 auto;
}

.sk-vue-board-title {
	/* Left padding clears the NcAppNavigation toggle button, which overlays the
	   top-left of the content when the sidebar is collapsible (Alain, 2026-07-18:
	   the toggle hid the first letter of the title). */
	padding: 12px 24px 0 52px;
	margin: 0;
}

.sk-vue-toolbar {
	display: flex;
	align-items: center;
	gap: 4px;
	padding-top: 8px;
}

.sk-toolbtn--on {
	background: var(--color-primary-element-light, var(--color-background-dark));
}
</style>
