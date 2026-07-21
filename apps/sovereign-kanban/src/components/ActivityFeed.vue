<!--
  - ActivityFeed — the whole board's journal, newest first.
  -
  - Steve, 2026-07-20, classes this phase 1: « la section activités, les logs, ça
  - va être important de le mettre aussi ». Each card already kept its own
  - activity.jsonl; this folds them together (GET /boards/{id}/activity).
  -
  - The wording mirrors CardDetail's per-card journal, plus the card's name — a
  - line here has to stand on its own, out of any card's context.
  -
  - @author Alain Lauzon <alauzon@alainlauzon.com>
  - @generated Claude (Opus 4.8)
  -->
<template>
	<div class="sk-feed">
		<p v-if="loading" class="sk-feed-note">{{ t('Chargement…') }}</p>
		<p v-else-if="!events.length" class="sk-feed-note">{{ t('Rien ne s’est encore passé sur ce tableau.') }}</p>
		<ul v-else class="sk-feed-list">
			<li v-for="(e, i) in events" :key="i" class="sk-feed-item">
				<span class="sk-feed-icon" aria-hidden="true">{{ icon(e.action) }}</span>
				<span class="sk-feed-text">
					<strong>{{ e.actor_label || e.actor || t('quelqu’un') }}</strong>
					{{ label(e) }}
					<span class="sk-feed-card">{{ e.card_title }}</span>
				</span>
				<time class="sk-feed-when" :datetime="e.ts">{{ when(e.ts) }}</time>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ActivityFeed',

	props: {
		boardId: { type: String, required: true },
	},

	data() {
		return {
			events: [],
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

		async load() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/sovereign-kanban-md-persistence/api/v1/boards/' + encodeURIComponent(this.boardId) + '/activity'),
				)
				this.events = res.data.activity || []
			} catch (e) {
				this.events = []
			} finally {
				this.loading = false
			}
		},

		icon(action) {
			return {
				created: '✨',
				updated: '✎',
				moved: '➡️',
				commented: '💬',
				done: '✅',
				reopened: '↩️',
				restored: '♻️',
				linked: '🔗',
				unlinked: '✂️',
				attached: '📎',
				detached: '🗑️',
			}[action] || '•'
		},

		label(e) {
			const fields = {
				title: this.t('le titre'),
				description: this.t('la description'),
				due_date: this.t('l’échéance'),
				start_date: this.t('la date de début'),
				assignees: this.t('les assignés'),
				priority: this.t('la priorité'),
				tags: this.t('les étiquettes'),
				phase: this.t('la phase'),
				linked_board: this.t('le tableau lié'),
			}
			switch (e.action) {
			case 'created': return this.t('a créé')
			case 'commented': return this.t('a commenté')
			case 'done': return this.t('a marqué comme faite')
			case 'reopened': return this.t('a rouvert')
			case 'restored': return this.t('a restauré')
			case 'moved': return e.detail && e.detail.to
				? this.t('a déplacé vers') + ' ' + e.detail.to + ' —'
				: this.t('a déplacé')
			case 'linked': return this.t('a lié')
			case 'unlinked': return this.t('a délié')
			case 'attached': return this.t('a joint un fichier à')
			case 'detached': return this.t('a retiré un fichier de')
			case 'updated': {
				const names = ((e.detail && e.detail.fields) || []).map((f) => fields[f] || f)
				return names.length ? this.t('a modifié') + ' ' + names.join(', ') + ' —' : this.t('a modifié')
			}
			default: return e.action
			}
		},

		when(ts) {
			const d = new Date(ts)
			return isNaN(d) ? ts : d.toLocaleString()
		},
	},
}
</script>

<style scoped>
.sk-feed-note {
	margin: 12px 0;
	color: var(--color-text-maxcontrast);
}

.sk-feed-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.sk-feed-item {
	display: flex;
	align-items: baseline;
	gap: 6px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.sk-feed-icon {
	flex: 0 0 auto;
}

.sk-feed-text {
	flex: 1 1 auto;
	min-width: 0;
}

.sk-feed-card {
	color: var(--color-text-maxcontrast);
}

.sk-feed-when {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-variant-numeric: tabular-nums;
}
</style>
