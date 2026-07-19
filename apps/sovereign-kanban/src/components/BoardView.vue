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
			@dragover.prevent
			@drop="onDrop($event, column)">
			<header class="sk-vue-column-head">
				<span class="sk-vue-column-name">{{ column }}</span>
				<span class="sk-vue-count">{{ (cardsByColumn[column] || []).length }}</span>
			</header>

			<article
				v-for="card in cardsByColumn[column] || []"
				:key="card.id"
				class="sk-vue-card"
				:draggable="!readOnly"
				@dragstart="onDragStart($event, card)"
				@click="$emit('open', card)">
				<div class="sk-vue-card-title">{{ card.title }}</div>
				<div v-if="card.excerpt" class="sk-vue-card-excerpt">{{ card.excerpt }}</div>
				<div v-if="cardMeta(card).length" class="sk-vue-card-meta">
					<span v-for="(m, i) in cardMeta(card)" :key="i" class="sk-vue-chip">{{ m }}</span>
				</div>
			</article>

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
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import { prioLabel } from '../priority.js'

export default {
	name: 'BoardView',

	components: { NcButton, NcActions, NcActionButton },

	props: {
		board: { type: Object, required: true },
		cardsByColumn: { type: Object, default: () => ({}) },
		readOnly: { type: Boolean, default: false },
		templates: { type: Array, default: () => [] },
	},

	emits: ['open', 'add-card', 'move-card', 'add-from-template'],

	data() {
		return {
			addingColumn: null,
			newTitle: '',
		}
	},

	methods: {
		t(s) {
			return s
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
			if (card.due_date) {
				out.push('📅 ' + String(card.due_date).replace('T', ' '))
			}
			;(card.assignees || []).forEach((a) => out.push('👤 ' + a))
			;(card.tags || []).forEach((tag) => out.push('🏷 ' + tag))
			if (card.priority) {
				out.push(prioLabel(card.priority))
			}
			return out
		},

		onDragStart(e, card) {
			e.dataTransfer.setData('text/plain', card.id)
		},

		onDrop(e, column) {
			if (this.readOnly) {
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
	align-items: flex-start;
	/* Columns that overflow scroll horizontally — they must NOT wrap to the next
	   line (Alain, 2026-07-18). nowrap + overflow-x:auto gives the scroll bar. */
	flex-wrap: nowrap;
	overflow-x: auto;
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
}

.sk-vue-column-head {
	display: flex;
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

.sk-vue-chip {
	font-size: 85%;
	background: var(--color-background-dark);
	border-radius: 6px;
	padding: 1px 6px;
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
