<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - Phase 2 shell — the Nextcloud-native frame. NcAppNavigation lists the boards
  - (loaded from the same REST API the vanilla app uses), NcAppNavigationNew puts
  - "+ Nouveau tableau" IN the navigation (D23 in the Deck→SK correspondence, the
  - phase-2 deliverable — the vanilla app put it in a toolbar above the board).
  -
  - The board CONTENT (columns, cards) is NOT here yet: it migrates gesture by
  - gesture behind the characterization test (Kate's gate). Until it does, this
  - shell shows the board name and an empty state. It mounts on #sk-vue, which the
  - live template does not yet provide, so the vanilla app keeps running until the
  - shell reaches parity — the cahier forbids a big-bang cutover.
-->
<template>
	<NcContent app-name="sovereign-kanban">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationNew :text="t('Nouveau tableau')" @click="onCreate" />
				<NcAppNavigationItem
					v-for="board in boards"
					:key="board.id"
					:name="board.name"
					:active="board.id === currentId"
					@click="currentId = board.id">
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
			<div v-else class="sk-vue-board">
				<h2>{{ currentBoard.name }}</h2>
				<p class="sk-vue-note">
					{{ t('Le contenu du tableau migre vers la nouvelle interface, colonne par colonne.') }}
				</p>
			</div>
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

const API = '/apps/sovereign-kanban-md-persistence/api/v1/boards'

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
	},

	data() {
		return {
			boards: [],
			currentId: null,
			loading: true,
		}
	},

	computed: {
		currentBoard() {
			return this.boards.find((b) => b.id === this.currentId) || null
		},
	},

	async mounted() {
		await this.loadBoards()
	},

	methods: {
		// Minimal translation stub until the app ships l10n; keeps strings in one
		// place and marks them for extraction later.
		t(s) {
			return s
		},

		sharedTitle(board) {
			return this.t('Partagé avec vous') + (board.owner ? ' — ' + board.owner : '')
		},

		async loadBoards() {
			this.loading = true
			try {
				const res = await axios.get(generateUrl(API))
				this.boards = res.data.boards || []
				if (!this.currentId && this.boards.length) {
					this.currentId = this.boards[0].id
				}
			} catch (e) {
				this.boards = []
			} finally {
				this.loading = false
			}
		},

		onCreate() {
			// Board creation moves to a Vue dialog in a later step; for now the
			// shell only lists. Kept as a wired no-op so the button exists.
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

.sk-vue-board {
	padding: 16px 24px;
}

.sk-vue-note {
	color: var(--color-text-maxcontrast);
}
</style>
