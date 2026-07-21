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
				<!-- Tous les tableaux (owned, active) -->
				<NcAppNavigationItem
					v-for="board in myBoards"
					:key="board.id"
					:name="board.name"
					:active="board.id === currentId"
					@click="select(board.id)">
					<template #icon>
						<span class="sk-nav-dot" :style="{ background: board.color || '#888' }" />
					</template>
					<template #actions>
						<NcActionButton :aria-label="t('Éditer le tableau')" @click="openBoardEdit(board)">
							<template #icon><span aria-hidden="true">✎</span></template>
							{{ t('Éditer') }}
						</NcActionButton>
						<NcActionButton :aria-label="t('Archiver le tableau')" @click="archiveBoard(board)">
							<template #icon><span aria-hidden="true">📦</span></template>
							{{ t('Archiver') }}
						</NcActionButton>
						<NcActionButton :aria-label="t('Supprimer le tableau')" @click="deleteBoard(board)">
							<template #icon><span aria-hidden="true">🗑</span></template>
							{{ t('Supprimer') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>

				<!-- Tableaux archivés (repliable) -->
				<NcAppNavigationItem
					v-if="archivedBoards.length"
					:name="t('Tableaux archivés') + ' (' + archivedBoards.length + ')'"
					:allow-collapse="true"
					:open="archivedOpen"
					@update:open="archivedOpen = $event">
					<template #icon><span aria-hidden="true">📦</span></template>
					<NcAppNavigationItem
						v-for="board in archivedBoards"
						:key="board.id"
						:name="board.name"
						:active="board.id === currentId"
						@click="select(board.id)">
						<template #icon>
							<span class="sk-nav-dot" :style="{ background: board.color || '#888' }" />
						</template>
						<template #actions>
							<NcActionButton :aria-label="t('Désarchiver le tableau')" @click="archiveBoard(board)">
								<template #icon><span aria-hidden="true">📤</span></template>
								{{ t('Désarchiver') }}
							</NcActionButton>
							<NcActionButton :aria-label="t('Supprimer le tableau')" @click="deleteBoard(board)">
								<template #icon><span aria-hidden="true">🗑</span></template>
								{{ t('Supprimer') }}
							</NcActionButton>
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationItem>

				<!-- Partagés par vous (mes tableaux, que je partage) -->

				<NcAppNavigationCaption v-if="sharedByMeBoards.length" :name="t('Partagés par vous')" />

				<NcAppNavigationItem

					v-for="board in sharedByMeBoards"

					:key="board.id"

					:name="board.name"

					:active="board.id === currentId"

					@click="select(board.id)">

					<template #icon>

						<span class="sk-nav-dot" :style="{ background: board.color || '#888' }" />

					</template>

					<template #counter>

						<span :title="t('Vous partagez ce tableau')">📤</span>

					</template>

					<template #actions>

						<NcActionButton :aria-label="t('Éditer le tableau')" @click="openBoardEdit(board)">

							<template #icon><span aria-hidden="true">✎</span></template>

							{{ t('Éditer') }}

						</NcActionButton>

						<NcActionButton :aria-label="t('Archiver le tableau')" @click="archiveBoard(board)">

							<template #icon><span aria-hidden="true">📦</span></template>

							{{ t('Archiver') }}

						</NcActionButton>

						<NcActionButton :aria-label="t('Supprimer le tableau')" @click="deleteBoard(board)">

							<template #icon><span aria-hidden="true">🗑</span></template>

							{{ t('Supprimer') }}

						</NcActionButton>

					</template>

				</NcAppNavigationItem>

				

				<!-- Partagés avec vous -->
				<NcAppNavigationCaption v-if="sharedBoards.length" :name="t('Partagés avec vous')" />
				<NcAppNavigationItem
					v-for="board in sharedBoards"
					:key="board.id"
					:name="board.name"
					:active="board.id === currentId"
					@click="select(board.id)">
					<template #icon>
						<span class="sk-nav-dot" :style="{ background: board.color || '#888' }" />
					</template>
					<template #counter>
						<span :title="sharedTitle(board)">👥</span>
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<NcButton
					type="tertiary"
					wide
					v-if="importAvailable"
					class="sk-import-btn"
					:aria-label="t('Importer depuis Deck')"
					@click="importDeck">
					<template #icon><span aria-hidden="true">⬇</span></template>
					{{ importing ? t('Import en cours…') : t('Importer depuis Deck') }}
				</NcButton>
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
				<div class="sk-workarea" :class="{ 'sk-workarea--split': (openedCard || boardPanelOpen) && wide }">
				<div class="sk-vue-board">
				<div class="sk-vue-board-header">
					<h2 class="sk-vue-board-title">{{ currentBoard.name }}</h2>
					<div class="sk-vue-toolbar">
						<span v-if="viewers.length" class="sk-presence" :title="t('Présent·es sur ce tableau')">
							<NcAvatar
								v-for="v in viewers"
								:key="v"
								:user="v"
								:size="26"
								:hide-status="true" />
						</span>
						<NcButton
							type="tertiary"
							:class="{ 'sk-toolbtn--on': filtersOpen || activeFilterCount }"
							:aria-label="t('Filtres')"
							:title="t('Filtres')"
							@click="filtersOpen = !filtersOpen">
							<span aria-hidden="true">⧩</span> {{ t('Filtres') }}{{ activeFilterCount ? ' (' + activeFilterCount + ')' : '' }}
						</NcButton>
						<NcButton
							type="tertiary"
							:class="{ 'sk-toolbtn--on': showArchived }"
							:aria-label="t('Afficher les archivées')"
							:title="t('Afficher les cartes archivées')"
							@click="showArchived = !showArchived">
							<span aria-hidden="true">📦</span> {{ t('Archivées') }}
						</NcButton>
						<div class="sk-present-wrap">
							<NcButton
								type="tertiary"
								:class="{ 'sk-toolbtn--on': presentOpen || compact || showCovers }"
								:aria-label="t('Présentation')"
								:title="t('Options de présentation')"
								@click="togglePresent">
								<span aria-hidden="true">🖼</span> {{ t('Présentation') }}
							</NcButton>
							<Teleport to="body">
							<div v-if="presentOpen" class="sk-present-backdrop" @click="presentOpen = false" />
							<div v-if="presentOpen" class="sk-present-menu" :style="presentStyle">
								<label class="sk-present-opt">
									<input type="checkbox" :checked="compact" @change="setPresent('compact', $event.target.checked)">
									{{ t('Affichage compact') }}
								</label>
								<label class="sk-present-opt">
									<input type="checkbox" :checked="showCovers" @change="setPresent('showCovers', $event.target.checked)">
									{{ t('Images de couverture') }}
								</label>
								<label class="sk-present-opt">
									<input type="checkbox" :checked="showId" @change="setPresent('showId', $event.target.checked)">
									{{ t('Afficher l\'identifiant') }}
								</label>
							</div>
							</Teleport>
						</div>
						<NcButton
							type="tertiary"
							:aria-label="t('Raccourcis clavier')"
							:title="t('Raccourcis clavier (?)')"
							@click="helpOpen = true">
							<span aria-hidden="true">⌨</span>
						</NcButton>
					</div>
					<NcButton
						class="sk-panel-toggle"
						type="tertiary"
						:class="{ 'sk-toolbtn--on': boardPanelOpen }"
						:aria-label="t('Détails du tableau')"
						:title="t('Détails du tableau (partage, étiquettes, corbeille, activité)')"
						@click="toggleBoardPanel">
						<span aria-hidden="true">▤</span> {{ t('Détails') }}
					</NcButton>
				</div>
				<FilterBar
					v-if="filtersOpen"
					:dimensions="filterDimensions"
					:selected="filters"
					:sort="sortBy"
					@sort="setSort"
					@toggle="toggleFilter"
					@reset="resetFilters"
					@close="filtersOpen = false" />
				<BoardView
					ref="boardView"
					:board="currentBoard"
					:cards-by-column="filteredCardsByColumn"
					:read-only="readOnly"
					:templates="templates"
					:compact="compact"
					:show-covers="showCovers"
					:show-id="showId"
					@open="openCard"
					@add-card="addCard"
					@move-card="moveCard"
					@add-from-template="addCardFromTemplate"
					@add-column="addColumn"
					@rename-column="renameColumn"
					@remove-column="removeColumn"
					@reorder-column="reorderColumn"
					@toggle-done="toggleCardDone"
					@delete-card="deleteCardTile"
					@mark-column-done="markColumnDone"
					@rename-card="renameCardTitle"
					@set-card-color="setCardColor"
					@archive-card="archiveCard"
					@archive-column="archiveColumn" />
				</div>

				<BoardPanel
					v-if="boardPanelOpen && currentBoard"
					class="sk-card-dock"
					:board-id="currentId"
					:board="currentBoard"
					:board-name="currentBoard.name"
					:can-share="!currentBoard.shared"
					:docked="wide"
					@refresh="loadCards"
					@close="boardPanelOpen = false" />

				<CardDetail
					v-if="openedCard"
					:key="openedCard.id"
					class="sk-card-dock"
					:docked="wide"
					:board-id="currentId"
					:boards="boards"
					:card="openedCard"
					:read-only="readOnly"
					:known-tags="knownTags"
					:palette="(currentBoard && currentBoard.tags) || []"
					:board-cards="allCards"
					@saved="onCardSaved"
					@deleted="onCardDeleted"
					@refresh="loadCards"
					@close="openedCard = null" />
				</div>
			</template>

			<BoardEditModal
				v-if="boardEditorOpen"
				:board="boardEditorTarget"
				@saved="onBoardSaved"
				@deleted="onBoardDeleted"
				@refresh="onBoardRefresh"
				@close="boardEditorOpen = false" />
		</NcAppContent>


		<!-- Keyboard-shortcut help (Alain, 2026-07-19: press ?). -->
		<Teleport to="body">
			<div v-if="helpOpen" class="sk-help-overlay" @click.self="helpOpen = false">
				<div class="sk-help-card" role="dialog" aria-modal="true">
					<h2 class="sk-help-title">{{ t('Raccourcis clavier') }}</h2>
					<dl class="sk-help-keys">
						<div><dt>?</dt><dd>{{ t('Afficher ou masquer cette aide') }}</dd></div>
						<div><dt>n</dt><dd>{{ t('Nouvelle carte (première liste)') }}</dd></div>
						<div><dt>f</dt><dd>{{ t('Filtres') }}</dd></div>
						<div><dt>a</dt><dd>{{ t('Afficher les cartes archivées') }}</dd></div>
						<div><dt>c</dt><dd>{{ t('Affichage compact') }}</dd></div>
						<div><dt>{{ t('Échap') }}</dt><dd>{{ t('Fermer (aide, carte)') }}</dd></div>
					</dl>
					<p class="sk-help-note">
						{{ t('Souris : glisser une carte entre listes, glisser une colonne pour la réordonner, cliquer le nom d\'une liste pour la renommer.') }}
					</p>
					<div class="sk-help-actions">
						<NcButton type="primary" @click="helpOpen = false">{{ t('Fermer') }}</NcButton>
					</div>
				</div>
			</div>
		</Teleport>
	</NcContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import BoardView from './components/BoardView.vue'
import BoardPanel from './components/BoardPanel.vue'
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
		NcAppNavigationCaption,
		NcAppNavigationNew,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActionButton,
		NcButton,
		NcAvatar,
		BoardView,
		BoardPanel,
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
			// The board's own side panel. It shares the right-hand dock with
			// CardDetail, so the two are mutually exclusive (Steve, 2026-07-20).
			boardPanelOpen: false,
			// Where to draw the Présentation menu once teleported to body.
			presentStyle: {},
			loading: true,
			boardEditorOpen: false,
			boardEditorTarget: null,
			templates: [],
			filtersOpen: false,
			showArchived: false,
			archivedOpen: false,
			presentOpen: false,
			compact: false,
			showCovers: false,
			showId: false,
			helpOpen: false,
			importing: false,
			importAvailable: !!(document.getElementById('sk-vue') && document.getElementById('sk-vue').dataset.importAvailable === '1'),
			wide: false,
			viewers: [],
			presenceTimer: null,
			filters: { tags: [], assignees: [], phases: [], priorities: [], status: [] },
			sortBy: (() => { try { return window.localStorage.getItem('sk-sort') || 'priority' } catch (e) { return 'priority' } })(),
		}
	},

	computed: {
		currentBoard() {
			return this.boards.find((b) => b.id === this.currentId) || null
		},

		// Sidebar sections (Alain, 2026-07-19): active owned / archived owned /
		// shared with me.
		myBoards() {
			return this.boards.filter((b) => !b.shared && !b.archived && !b.shared_by_me)
		},

		// Boards I own AND share out — « Partagés par vous ». They used to sit with
		// my private boards, and a group share to a group I belong to ALSO made them
		// appear under « Partagés avec vous » (Alain, 2026-07-20).
		sharedByMeBoards() {
			return this.boards.filter((b) => !b.shared && !b.archived && b.shared_by_me)
		},

		archivedBoards() {
			return this.boards.filter((b) => !b.shared && b.archived)
		},

		sharedBoards() {
			return this.boards.filter((b) => b.shared)
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
			const f = this.filters
			const hasFilters = this.activeFilterCount > 0
			const out = {}
			for (const [col, cards] of Object.entries(this.cardsByColumn)) {
				out[col] = (cards || []).filter((card) => {
					// Archived cards are hidden unless the « Afficher les archivées »
					// toggle is on (Alain, 2026-07-19).
					if (!this.showArchived && card.archived) {
						return false
					}
					return !hasFilters || this.matchesFilters(card, f)
				})
			}
			if (this.sortBy === 'priority') {
				const rank = (c) => { const n = parseInt(c.priority, 10); return Number.isFinite(n) ? n : 99 }
				for (const col of Object.keys(out)) {
					out[col] = out[col].slice().sort((a, b) => rank(a) - rank(b))
				}
			}
			return out
		},
	},

	watch: {
		// Restart the presence heartbeat whenever the open board changes, and
		// remember the board so a refresh (F5) reopens the same one, not board[0]
		// (Alain, 2026-07-19).
		currentId(id) {
			this.startPresence(id)
			if (id) {
				// Reflect the open board in the URL (#<board>) so it is shareable and
				// survives a refresh; the « Ouvrir → » button of a carte-tableau just
				// sets this hash (Alain, 2026-07-20).
				const hash = '#' + encodeURIComponent(id)
				if (window.location.hash !== hash) {
					window.location.hash = hash
				}
				try {
					window.localStorage.setItem('sk-last-board', id)
				} catch (e) {
					// localStorage unavailable (private mode): fall back to board[0].
				}
			}
		},
	},

	async mounted() {
		this.loadPresent()
		this.updateWide()
		window.addEventListener('keydown', this.onKeydown)
		window.addEventListener('resize', this.updateWide)
		window.addEventListener('hashchange', this.onHashChange)
		await this.loadBoards()
		this.loadTemplates()
	},

	beforeUnmount() {
		window.removeEventListener('keydown', this.onKeydown)
		window.removeEventListener('resize', this.updateWide)
		window.removeEventListener('hashchange', this.onHashChange)
		this.stopPresence()
	},

	methods: {
		t(s) {
			return s
		},

		// Board presence (Alain, 2026-07-19): heartbeat every 15 s while a board is
		// open, showing the avatars of who else is looking. Ephemeral, cache-backed.
		startPresence(boardId) {
			this.stopPresence()
			this.viewers = []
			if (!boardId) {
				return
			}
			this.heartbeat(boardId)
			this.presenceTimer = window.setInterval(() => this.heartbeat(boardId), 15000)
		},

		stopPresence() {
			if (this.presenceTimer) {
				window.clearInterval(this.presenceTimer)
				this.presenceTimer = null
			}
		},

		async heartbeat(boardId) {
			if (boardId !== this.currentId) {
				return
			}
			try {
				const res = await axios.post(this.url('/boards/' + encodeURIComponent(boardId) + '/presence'))
				this.viewers = res.data.viewers || []
			} catch (e) {
				this.viewers = []
			}
		},

		// Dock the card editor to the right above this width; below it, the card
		// stays a centred overlay (Alain, 2026-07-19).
		updateWide() {
			this.wide = window.innerWidth >= 1200
		},

		// Global keyboard shortcuts (Alain, 2026-07-19). Ignored while typing in a
		// field or when a card modal is open, so they never eat real input.
		onKeydown(e) {
			const el = e.target
			const typing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)
			if (e.key === 'Escape' && this.helpOpen) {
				this.helpOpen = false
				return
			}
			if (typing || this.openedCard || this.boardEditorOpen) {
				return
			}
			if (e.key === '?') {
				this.helpOpen = !this.helpOpen
				e.preventDefault()
				return
			}
			if (this.helpOpen || !this.currentBoard) {
				return
			}
			if (e.key === 'n' && !this.readOnly) {
				if (this.$refs.boardView) {
					this.$refs.boardView.startAddFirst()
				}
				e.preventDefault()
			} else if (e.key === 'f') {
				this.filtersOpen = !this.filtersOpen
				e.preventDefault()
			} else if (e.key === 'a') {
				this.showArchived = !this.showArchived
				e.preventDefault()
			} else if (e.key === 'c') {
				this.setPresent('compact', !this.compact)
				e.preventDefault()
			}
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
					// Prefer the board named in the URL (#<board>), then the last-viewed
					// (localStorage), then the first board (Alain, 2026-07-20).
					const has = (id) => id && this.boards.some((b) => b.id === id)
					const fromHash = this.boardIdFromHash()
					let saved = null
					try {
						saved = window.localStorage.getItem('sk-last-board')
					} catch (e) {
						saved = null
					}
					await this.select(has(fromHash) ? fromHash : (has(saved) ? saved : this.boards[0].id))
				}
			} catch (e) {
				this.boards = []
			} finally {
				this.loading = false
			}
		},

		boardIdFromHash() {
			try {
				return decodeURIComponent((window.location.hash || '').replace(/^#/, ''))
			} catch (e) {
				return ''
			}
		},

		// Follow the URL hash to a board — the « Ouvrir → » button of a carte-tableau
		// navigates by just setting window.location.hash (Alain, 2026-07-20).
		onHashChange() {
			const id = this.boardIdFromHash()
			if (id && id !== this.currentId && this.boards.some((b) => b.id === id)) {
				this.select(id)
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
		// The rev the client last read, sent with every structural write so the
		// server can refuse a stale one (Nisha, e0442c). null = un-versioned.
		boardRev() {
			return this.currentBoard ? this.currentBoard.rev : null
		},

		// A structural write came back 409: someone else changed the board first.
		// Reload to the current state and tell the user to redo the gesture, rather
		// than silently keep their stale view (which was the whole bug).
		async onBoardWriteError(e, fallbackMessage) {
			if (e && e.response && e.response.status === 409) {
				await this.loadBoards()
				await this.loadCards()
				// eslint-disable-next-line no-alert
				window.alert(this.t('Le tableau a été modifié par quelqu\'un d\'autre. Il a été rechargé — refais ton geste.'))
				return
			}
			// eslint-disable-next-line no-alert
			window.alert(fallbackMessage)
		},

		async addColumn(name) {
			try {
				await axios.post(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/columns'),
					{ name, baseRev: this.boardRev() },
				)
				await this.loadBoards()
				await this.loadCards()
			} catch (e) {
				await this.onBoardWriteError(e, this.t('Impossible d\'ajouter la liste (nom déjà pris ?).'))
			}
		},

		async renameColumn({ from, to }) {
			try {
				await axios.put(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/columns/rename'),
					{ from, to, baseRev: this.boardRev() },
				)
				await this.loadBoards()
				await this.loadCards()
			} catch (e) {
				await this.onBoardWriteError(e, this.t('Impossible de renommer la liste (nom déjà pris ?).'))
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
					{ columns: cols, baseRev: this.boardRev() },
				)
				await this.loadBoards()
				await this.loadCards()
			} catch (e) {
				await this.onBoardWriteError(e, this.t('Impossible de réordonner les listes.'))
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
					{ data: { name, baseRev: this.boardRev() } },
				)
				await this.loadBoards()
				await this.loadCards()
			} catch (e) {
				await this.onBoardWriteError(e, this.t('Impossible de supprimer la liste.'))
			}
		},

		// Quick actions from a tile, without opening the card (Alain, 2026-07-19).
		async toggleCardDone(card) {
			const value = card.completed_at ? '' : new Date().toISOString()
			try {
				await axios.put(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(card.id)),
					{ completed_at: value },
				)
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible de changer le statut.'))
			}
		},

		// Archive or unarchive a card (card ⋯ menu). Toggles on card.archived.
		async archiveCard(card) {
			const value = card.archived ? '' : new Date().toISOString()
			try {
				await axios.put(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(card.id)),
					{ archived: value },
				)
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible d\'archiver la carte.'))
			}
		},

		// Archive every non-archived card in a column (column ⋯ menu).
		async archiveColumn(column) {
			const cards = (this.cardsByColumn[column] || []).filter((c) => !c.archived)
			if (!cards.length) {
				return
			}
			const now = new Date().toISOString()
			try {
				await Promise.all(cards.map((c) => axios.put(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(c.id)),
					{ archived: now },
				)))
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible d\'archiver les cartes de la liste.'))
			}
		},

		// Set (or clear, with '') a card's colour from the card ⋯ menu swatches.
		async setCardColor({ card, color }) {
			try {
				await axios.put(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(card.id)),
					{ color },
				)
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible de changer la couleur.'))
			}
		},

		// Rename a card's title from the tile (card ⋯ menu → Modifier le titre).
		async renameCardTitle({ card, title }) {
			try {
				await axios.put(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(card.id)),
					{ title },
				)
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible de renommer la carte.'))
			}
		},

		// Mark every open card in a column done (column ⋯ menu, Alain 2026-07-19).
		async markColumnDone(column) {
			const cards = (this.cardsByColumn[column] || []).filter((c) => !c.completed_at)
			if (!cards.length) {
				return
			}
			const now = new Date().toISOString()
			try {
				await Promise.all(cards.map((c) => axios.put(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(c.id)),
					{ completed_at: now },
				)))
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible de terminer les cartes de la liste.'))
			}
		},

		async deleteCardTile(card) {
			// eslint-disable-next-line no-alert
			if (!window.confirm(this.t('Déplacer « ') + card.title + this.t(' » à la corbeille ?'))) {
				return
			}
			try {
				await axios.delete(
					this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(card.id)),
				)
				await this.loadCards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible de supprimer la carte.'))
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

		// Presentation prefs (compact / cover images) persist across sessions,
		// globally (a display taste, not board data). Alain, 2026-07-19.
		loadPresent() {
			try {
				const raw = window.localStorage.getItem('sk-present')
				if (raw) {
					const p = JSON.parse(raw)
					this.compact = !!p.compact
					this.showCovers = !!p.showCovers
					this.showId = !!p.showId
				}
			} catch (e) {
				// ignore a corrupt value
			}
		},

		setPresent(key, value) {
			this[key] = value
			try {
				window.localStorage.setItem('sk-present', JSON.stringify({ compact: this.compact, showCovers: this.showCovers, showId: this.showId }))
			} catch (e) {
				// storage disabled — the toggle still works for this session
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
			// One panel at a time on the right — two stacked panels is the overlap
			// bug (8f173f) made worse.
			this.boardPanelOpen = false
			// Fetch the full card (the list carries an excerpt, not the body). The
			// list item already carries created_at (the detail endpoint doesn't), so
			// merge it in for the card's summary line — no backend round-trip.
			const res = await axios.get(
				this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(card.id)),
			)
			this.openedCard = { created_at: card.created_at, ...res.data.card }
		},

		// Teleported to body and positioned from the button, like BoardView's column
		// ⋯ menu. Kept inside the board it sat in the board's stacking context and
		// the columns painted over it (Alain, 2026-07-20).
		togglePresent(ev) {
			if (this.presentOpen) {
				this.presentOpen = false
				return
			}
			const rect = ev.currentTarget.getBoundingClientRect()
			this.presentStyle = {
				top: (rect.bottom + 4) + 'px',
				left: Math.max(8, rect.right - 240) + 'px',
			}
			this.presentOpen = true
		},

		toggleBoardPanel() {
			this.boardPanelOpen = !this.boardPanelOpen
			if (this.boardPanelOpen) {
				this.openedCard = null
			}
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

		// Import the current user's own Deck boards into Sovereign Kanban. Boards
		// that already exist are skipped (reported in the result).
		async importDeck() {
			// eslint-disable-next-line no-alert
			if (!window.confirm(this.t('Importer vos tableaux Deck dans Sovereign Kanban ? Les tableaux déjà présents seront ignorés.'))) {
				return
			}
			this.importing = true
			try {
				const res = await axios.post(generateUrl('/apps/sovereign-kanban-import/api/v1/import'))
				// Reload the board list and reset the button BEFORE the alert, so the
				// imported boards are already visible behind it and « Import en cours… »
				// does not linger while the (blocking) alert is open.
				await this.loadBoards()
				this.importing = false
				// eslint-disable-next-line no-alert
				window.alert(res.data.message || this.t('Import terminé.'))
			} catch (e) {
				const msg = (e.response && e.response.data && e.response.data.message) || this.t('Import impossible.')
				// eslint-disable-next-line no-alert
				window.alert(msg)
			} finally {
				this.importing = false
			}
		},

		// Archive or unarchive a board (sidebar action). Toggles on board.archived;
		// archived boards move to the collapsible « Tableaux archivés » section.
		async archiveBoard(board) {
			const value = board.archived ? '' : new Date().toISOString()
			try {
				await axios.put(this.url('/boards/' + encodeURIComponent(board.id)), { archived: value })
				if (!board.archived) {
					this.archivedOpen = true
				}
				await this.loadBoards()
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Impossible d\'archiver le tableau.'))
			}
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

		setSort(value) {
			this.sortBy = value
			try { window.localStorage.setItem('sk-sort', value) } catch (e) { /* private */ }
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
/* Work area: board alone (full width), or board + docked card editor side by
   side above 1200px (Alain, 2026-07-19). */
.sk-workarea {
	height: 100%;
	min-height: 0;
	display: flex;
	flex-direction: column;
}

.sk-workarea--split {
	flex-direction: row;
	align-items: stretch;
	gap: 0;
}

.sk-workarea > .sk-vue-board {
	flex: 1 1 0;
	min-width: 0;
	/* The toolbar used to spill out of the board and paint OVER the card panel —
	   Steve's « le menu du tableau embarque par-dessus la carte » (2026-07-20).
	   The header no longer overflows (nowrap + scroll), but the stacking order
	   makes it impossible: the board sits below, the panel above. */
	position: relative;
	z-index: 1;
}

.sk-workarea--split > .sk-card-dock {
	/* Above the board, always: whatever the board paints outside its box stays
	   behind the panel rather than across it (8f173f). */
	position: relative;
	z-index: 2;
	flex: 0 0 46%;
	min-width: 380px;
	max-width: 680px;
	min-height: 0;
	border-left: 1px solid var(--color-border);
	background: var(--color-main-background);
	overflow: hidden;
}

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
	flex-wrap: nowrap;
}

.sk-vue-board-title {
	/* Left padding clears the NcAppNavigation toggle button, which overlays the
	   top-left of the content when the sidebar is collapsible (Alain, 2026-07-18:
	   the toggle hid the first letter of the title). */
	padding: 12px 24px 0 52px;
	margin: 0;
	/* Shrinks and truncates so the toolbar never gets squeezed onto its own line. */
	flex: 0 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sk-vue-toolbar {
	display: flex;
	align-items: center;
	gap: 4px;
	padding-top: 8px;
	/* The header is one row, always. Adding a button used to push the whole bar
	   into a vertical stack that shoved the board down (Alain, 2026-07-20) — so
	   the toolbar keeps its line and scrolls sideways rather than wrapping, and
	   the title gives up its width before the buttons do. */
	flex: 1 1 auto;
	flex-wrap: nowrap;
	justify-content: flex-end;
	min-width: 0;
	overflow-x: auto;
}

.sk-panel-toggle {
	flex: 0 0 auto;
	margin-top: 8px;
}

/* Presence avatars (who else is on this board). */
.sk-presence {
	display: inline-flex;
	align-items: center;
	margin-right: 6px;
}

.sk-presence > * + * {
	margin-left: -8px;
}

.sk-toolbtn--on {
	background: var(--color-primary-element-light, var(--color-background-dark));
}

/* Presentation dropdown (compact / cover images). */
.sk-present-wrap {
	position: relative;
}

.sk-present-backdrop {
	position: fixed;
	inset: 0;
	z-index: 10000;
}

.sk-present-menu {
	position: fixed;
	z-index: 10001;
	min-width: 220px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	box-shadow: 0 2px 12px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sk-present-opt {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 6px;
	border-radius: 6px;
	cursor: pointer;
}

.sk-present-opt:hover {
	background: var(--color-background-hover);
}

/* Keyboard-shortcut help overlay. */
.sk-help-overlay {
	position: fixed;
	inset: 0;
	z-index: 10050;
	background: rgba(0, 0, 0, 0.4);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
}

.sk-help-card {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large, 12px);
	box-shadow: 0 6px 30px rgba(0, 0, 0, 0.3);
	padding: 20px 24px;
	max-width: 440px;
	width: 100%;
}

.sk-help-title {
	margin: 0 0 12px;
}

.sk-help-keys {
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sk-help-keys > div {
	display: flex;
	align-items: baseline;
	gap: 12px;
}

.sk-help-keys dt {
	flex: 0 0 64px;
	font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
	font-weight: 700;
	background: var(--color-background-dark);
	border-radius: 6px;
	padding: 1px 8px;
	text-align: center;
}

.sk-help-keys dd {
	margin: 0;
}

.sk-help-note {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
	margin: 14px 0;
}

.sk-help-actions {
	display: flex;
	justify-content: flex-end;
}

.sk-trash-card {
	max-width: 520px;
}

.sk-trash-list {
	list-style: none;
	margin: 8px 0 14px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: 50vh;
	overflow-y: auto;
}

.sk-trash-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 4px 6px;
	border-bottom: 1px solid var(--color-border);
}

.sk-trash-title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sk-trash-acts {
	display: flex;
	gap: 4px;
	flex: 0 0 auto;
}
</style>
