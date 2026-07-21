<!--
  - BoardPanel — the board's own side panel (Steve, 2026-07-20).
  -
  - Deck has a panel to the right of the BOARD (not of a card) holding Partage,
  - Étiquettes, Éléments supprimés and Activité. Sovereign Kanban had none: sharing
  - was buried in ⋯ → Éditer, and Steve had to hunt for it. He classes this as
  - phase 1, not phase 2.
  -
  - It shares the right-hand dock with CardDetail and the two are mutually
  - exclusive — opening one closes the other. Two stacked panels on the right is
  - the overlap bug (8f173f) made worse, not a feature.
  -
  - Tabs land one at a time (sub-cards of 5b259e); a tab that is not built yet says
  - so plainly rather than showing an empty box that reads as broken.
  -
  - @author Alain Lauzon <alauzon@alainlauzon.com>
  - @generated Claude (Opus 4.8)
  -->
<template>
	<div class="sk-board-panel" :class="{ 'sk-board-panel--docked': docked }">
		<div class="sk-board-panel-head">
			<h3 class="sk-board-panel-title">{{ boardName }}</h3>
			<NcButton type="tertiary" :aria-label="t('Fermer le volet')" :title="t('Fermer le volet')" @click="$emit('close')">
				✕
			</NcButton>
		</div>

		<div class="sk-board-panel-tabs" role="tablist">
			<button
				v-for="tab in tabs"
				:key="tab.id"
				type="button"
				role="tab"
				class="sk-board-panel-tab"
				:class="{ 'sk-board-panel-tab--on': active === tab.id }"
				:aria-selected="active === tab.id"
				@click="active = tab.id">
				<span aria-hidden="true">{{ tab.icon }}</span> {{ tab.label }}
			</button>
		</div>

		<div class="sk-board-panel-body">
			<SharePanel v-if="active === 'share' && canShare" :board-id="boardId" />
			<p v-else-if="active === 'share'" class="sk-board-panel-note">
				{{ t('Seul le propriétaire du tableau peut le partager.') }}
			</p>

			<ActivityFeed v-else-if="active === 'activity'" :board-id="boardId" />

			<TagPalette v-else-if="active === 'tags' && board" :board="board" @saved="$emit('refresh')" />

			<TrashList v-else-if="active === 'trash'" :board-id="boardId" @restored="$emit('refresh')" />

			<p v-else class="sk-board-panel-note">
				{{ t('Pas encore livré.') }}
			</p>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import ActivityFeed from './ActivityFeed.vue'
import SharePanel from './SharePanel.vue'
import TagPalette from './TagPalette.vue'
import TrashList from './TrashList.vue'

export default {
	name: 'BoardPanel',

	components: { NcButton, ActivityFeed, SharePanel, TagPalette, TrashList },

	props: {
		boardId: { type: String, required: true },
		// The full board record — the palette editor needs its name and colour to
		// PUT them back unchanged alongside the tags.
		board: { type: Object, default: null },
		boardName: { type: String, default: '' },
		// A received (shared-with-me) board cannot be reshared from here.
		canShare: { type: Boolean, default: false },
		// Side-by-side with the board on a wide screen, overlay on a narrow one —
		// same rule as the card panel.
		docked: { type: Boolean, default: false },
	},

	emits: ['close', 'refresh'],

	data() {
		return {
			active: 'share',
			tabs: [
				{ id: 'share', icon: '🔗', label: this.t('Partage') },
				{ id: 'tags', icon: '🏷', label: this.t('Étiquettes') },
				{ id: 'trash', icon: '🗑', label: this.t('Éléments supprimés') },
				{ id: 'activity', icon: '⚡', label: this.t('Activité') },
			],
		}
	},

	methods: {
		t(s) {
			return s
		},
	},
}
</script>

<style scoped>
.sk-board-panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
	height: 100%;
	overflow: hidden;
	padding: 12px;
	background: var(--color-main-background);
	border-left: 1px solid var(--color-border);
}

.sk-board-panel-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.sk-board-panel-title {
	margin: 0;
	font-size: 1.1em;
	text-wrap: balance;
}

.sk-board-panel-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
}

.sk-board-panel-tab {
	padding: 6px 10px;
	border: none;
	border-bottom: 2px solid transparent;
	border-radius: 0;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.sk-board-panel-tab--on {
	border-bottom-color: var(--color-primary-element);
	color: var(--color-main-text);
	font-weight: bold;
}

.sk-board-panel-body {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
}

.sk-board-panel-note {
	margin: 12px 0;
	color: var(--color-text-maxcontrast);
}
</style>
