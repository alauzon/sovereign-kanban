<!--
  Typed relations between cards (Alain, 2026-07-19): list existing links, add one
  by picking a type and searching an existing card by title, or create a new card
  already linked. The reciprocal is stored server-side on the other card.
-->
<template>
	<div class="sk-relations">
		<div class="sk-relations-head">
			<span>{{ t('Relations') }}</span>
			<button
				v-if="!readOnly"
				type="button"
				class="sk-rel-add-btn"
				@click="picking = !picking">
				{{ picking ? t('Fermer') : t('＋ Ajouter une relation') }}
			</button>
		</div>

		<ul v-if="items.length" class="sk-rel-list">
			<li v-for="rel in items" :key="rel.type + rel.card" class="sk-rel-item">
				<span class="sk-rel-type">{{ typeLabel(rel.type) }}</span>
				<span class="sk-rel-title" :class="{ 'sk-rel-done': rel.done, 'sk-rel-gone': !rel.title }">
					{{ rel.title || t('(carte supprimée)') }}
				</span>
				<button
					v-if="!readOnly"
					type="button"
					class="sk-rel-remove"
					:aria-label="t('Retirer la relation')"
					:title="t('Retirer la relation')"
					@click="remove(rel.card)">
					✕
				</button>
			</li>
		</ul>
		<p v-else class="sk-rel-empty">{{ t('Aucune relation.') }}</p>

		<div v-if="picking && !readOnly" class="sk-rel-picker">
			<select v-model="pickType" class="sk-rel-select">
				<option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
			</select>
			<input
				v-model="query"
				type="text"
				class="sk-rel-search"
				:placeholder="t('Chercher une carte par titre…')">
			<ul class="sk-rel-results">
				<li
					v-for="c in candidates"
					:key="c.id"
					class="sk-rel-result"
					@click="link(c.id)">
					{{ c.title }}
				</li>
				<li
					v-if="canCreate"
					class="sk-rel-result sk-rel-create"
					@click="createAndLink">
					{{ t('＋ Créer') }} «{{ query.trim() }}» {{ t('et lier') }}
				</li>
				<li v-if="!candidates.length && !canCreate" class="sk-rel-noresult">
					{{ t('Tapez un titre à chercher ou à créer.') }}
				</li>
			</ul>
			<p v-if="error" class="sk-rel-error">{{ error }}</p>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Value stored (English) → French label shown. Reciprocals live on the backend.
const TYPE_LABELS = {
	child: 'Enfant',
	parent: 'Mère',
	depends: 'A besoin de',
	required: 'Nécessaire pour',
	related: 'Relié',
}

export default {
	name: 'RelationsField',

	props: {
		boardId: { type: String, required: true },
		cardId: { type: String, required: true },
		// Resolved relations from the card detail: [{type, card, title, done}].
		relations: { type: Array, default: () => [] },
		// Flat list of the board's cards for the title search: [{id, title}].
		boardCards: { type: Array, default: () => [] },
		readOnly: { type: Boolean, default: false },
	},

	emits: ['changed'],

	data() {
		return {
			items: [...this.relations],
			picking: false,
			pickType: 'related',
			query: '',
			error: '',
		}
	},

	computed: {
		typeOptions() {
			return Object.keys(TYPE_LABELS).map((value) => ({ value, label: this.t(TYPE_LABELS[value]) }))
		},

		// Cards matching the query, minus this card and the already-linked ones.
		candidates() {
			const q = this.query.trim().toLowerCase()
			const linked = new Set(this.items.map((r) => r.card))
			return this.boardCards
				.filter((c) => c.id !== this.cardId && !linked.has(c.id))
				.filter((c) => q === '' ? false : (c.title || '').toLowerCase().includes(q))
				.slice(0, 8)
		},

		// Offer "create" only when the typed title matches no existing card exactly.
		canCreate() {
			const q = this.query.trim()
			if (q === '') {
				return false
			}
			return !this.boardCards.some((c) => (c.title || '').toLowerCase() === q.toLowerCase())
		},
	},

	watch: {
		// Keep in sync if the parent reloads the card.
		relations(next) {
			this.items = [...next]
		},
	},

	methods: {
		t(s) {
			return s
		},

		typeLabel(type) {
			return this.t(TYPE_LABELS[type] || type)
		},

		url() {
			return generateUrl(
				'/apps/sovereign-kanban-md-persistence/api/v1/boards/'
				+ encodeURIComponent(this.boardId) + '/cards/' + encodeURIComponent(this.cardId) + '/relations',
			)
		},

		async link(target) {
			await this.post({ type: this.pickType, target })
		},

		async createAndLink() {
			await this.post({ type: this.pickType, newTitle: this.query.trim() })
		},

		async post(body) {
			this.error = ''
			try {
				const res = await axios.post(this.url(), body)
				this.applyResult(res.data.card)
				this.query = ''
				this.picking = false
			} catch (e) {
				this.error = this.t('Impossible d\'ajouter la relation. Rafraîchissez (F5) si la session a expiré.')
			}
		},

		async remove(target) {
			this.error = ''
			try {
				const res = await axios.delete(this.url() + '/' + encodeURIComponent(target))
				this.applyResult(res.data.card)
			} catch (e) {
				this.error = this.t('Impossible de retirer la relation.')
			}
		},

		applyResult(card) {
			this.items = (card && card.relations) || []
			this.$emit('changed', this.items)
		},
	},
}
</script>

<style scoped>
.sk-relations {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sk-relations-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.sk-relations-head > span {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-rel-add-btn {
	background: none;
	border: none;
	color: var(--color-primary-element);
	cursor: pointer;
	padding: 2px 4px;
}

.sk-rel-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.sk-rel-item {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sk-rel-type {
	flex: 0 0 auto;
	font-size: 80%;
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 0 8px;
	line-height: 1.6;
	white-space: nowrap;
}

.sk-rel-title {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sk-rel-done {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.sk-rel-gone {
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.sk-rel-remove {
	flex: 0 0 auto;
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.sk-rel-empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-rel-picker {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-hover);
}

.sk-rel-search,
.sk-rel-select {
	width: 100%;
	box-sizing: border-box;
}

.sk-rel-results {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
	max-height: 180px;
	overflow-y: auto;
}

.sk-rel-result {
	padding: 4px 8px;
	border-radius: var(--border-radius, 6px);
	cursor: pointer;
}

.sk-rel-result:hover {
	background: var(--color-background-dark);
}

.sk-rel-create {
	color: var(--color-primary-element);
}

.sk-rel-noresult {
	padding: 4px 8px;
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-rel-error {
	margin: 0;
	color: var(--color-error, #e9322d);
	font-size: 90%;
}
</style>
