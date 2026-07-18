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
				@saved="onCardSaved"
				@deleted="onCardDeleted"
				@close="openedCard = null" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import BoardView from './components/BoardView.vue'
import CardDetail from './components/CardDetail.vue'

const BOARDS = '/apps/sovereign-kanban-md-persistence/api/v1/boards'

export default {
	name: 'App',

	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		BoardView,
		CardDetail,
	},

	data() {
		return {
			boards: [],
			currentId: null,
			cardsByColumn: {},
			openedCard: null,
			loading: true,
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
