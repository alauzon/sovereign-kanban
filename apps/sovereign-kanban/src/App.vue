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
				<h2 class="sk-vue-board-title">{{ currentBoard.name }}</h2>
				<BoardView
					:board="currentBoard"
					:cards-by-column="cardsByColumn"
					:read-only="readOnly"
					@open="openCard"
					@add-card="addCard"
					@move-card="moveCard" />
			</template>

			<CardDetail
				v-if="openedCard"
				:board-id="currentId"
				:card="openedCard"
				:read-only="readOnly"
				:known-tags="knownTags"
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
import BoardView from './components/BoardView.vue'
import CardDetail from './components/CardDetail.vue'
import BoardEditModal from './components/BoardEditModal.vue'

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
		BoardView,
		CardDetail,
		BoardEditModal,
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
	},

	async mounted() {
		await this.loadBoards()
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
			await axios.post(this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards'), {
				title,
				column,
			})
			await this.loadCards()
		},

		async moveCard({ cardId, column }) {
			await axios.put(
				this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(cardId) + '/move'),
				{ toColumn: column },
			)
			await this.loadCards()
		},

		async openCard(card) {
			// Fetch the full card (the list carries an excerpt, not the body).
			const res = await axios.get(
				this.url('/boards/' + encodeURIComponent(this.currentId) + '/cards/' + encodeURIComponent(card.id)),
			)
			this.openedCard = res.data.card
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

.sk-vue-board-title {
	/* Left padding clears the NcAppNavigation toggle button, which overlays the
	   top-left of the content when the sidebar is collapsible (Alain, 2026-07-18:
	   the toggle hid the first letter of the title). */
	padding: 12px 24px 0 52px;
	margin: 0;
}
</style>
