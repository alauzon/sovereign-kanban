<!--
  - TrashList — the board's deleted cards, restorable.
  -
  - Extracted from the « Corbeille » modal that App.vue carried, so it can live in
  - the board panel's « Éléments supprimés » tab (Steve, 2026-07-20: Deck keeps
  - deleted items in the panel, not behind a toolbar button). The behaviour is the
  - one that was already there — list, restore, purge — moved, not rewritten.
  -
  - Emits 'restored' so the board reloads its cards: a restored card must reappear
  - in its column without a manual refresh.
  -
  - @author Alain Lauzon <alauzon@alainlauzon.com>
  - @generated Claude (Opus 4.8)
  -->
<template>
	<div class="sk-trash">
		<p v-if="loading" class="sk-trash-note">{{ t('Chargement…') }}</p>
		<p v-else-if="!cards.length" class="sk-trash-note">{{ t('Aucun élément supprimé.') }}</p>
		<ul v-else class="sk-trash-list">
			<li v-for="c in cards" :key="c.id" class="sk-trash-item">
				<span class="sk-trash-title">{{ c.title || t('(sans titre)') }}</span>
				<span class="sk-trash-acts">
					<NcButton type="tertiary" :aria-label="t('Restaurer')" :title="t('Restaurer')" @click="restore(c)">↩</NcButton>
					<NcButton type="tertiary" :aria-label="t('Supprimer définitivement')" :title="t('Supprimer définitivement')" @click="purge(c)">🗑</NcButton>
				</span>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'

export default {
	name: 'TrashList',

	components: { NcButton },

	props: {
		boardId: { type: String, required: true },
	},

	emits: ['restored'],

	data() {
		return {
			cards: [],
			loading: true,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t(s) {
			return s
		},

		url(path) {
			return generateUrl('/apps/sovereign-kanban-md-persistence/api/v1' + path)
		},

		base() {
			return '/boards/' + encodeURIComponent(this.boardId) + '/trash'
		},

		async load() {
			this.loading = true
			try {
				const res = await axios.get(this.url(this.base()))
				this.cards = res.data.trash || []
			} catch (e) {
				this.cards = []
			} finally {
				this.loading = false
			}
		},

		async restore(card) {
			try {
				const res = await axios.post(this.url(this.base() + '/' + encodeURIComponent(card.id) + '/restore'))
				this.cards = res.data.trash || []
				this.$emit('restored')
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Restauration impossible.'))
			}
		},

		async purge(card) {
			// eslint-disable-next-line no-alert
			if (!window.confirm(this.t('Supprimer définitivement « ') + card.title + ' » ? Cette action est irréversible.')) {
				return
			}
			try {
				const res = await axios.delete(this.url(this.base() + '/' + encodeURIComponent(card.id)))
				this.cards = res.data.trash || []
			} catch (e) {
				// eslint-disable-next-line no-alert
				window.alert(this.t('Suppression impossible.'))
			}
		},
	},
}
</script>

<style scoped>
.sk-trash-note {
	margin: 12px 0;
	color: var(--color-text-maxcontrast);
}

.sk-trash-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.sk-trash-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.sk-trash-title {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sk-trash-acts {
	display: flex;
	flex: 0 0 auto;
	gap: 2px;
}
</style>
