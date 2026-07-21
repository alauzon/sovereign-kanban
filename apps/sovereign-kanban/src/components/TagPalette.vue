<!--
  - TagPalette — the board's label palette, edited from the board panel.
  -
  - Extracted from BoardEditModal (Steve, 2026-07-20: Deck keeps labels in the
  - side panel, and he had to open « Éditer le tableau » to touch them). Same move
  - as TrashList: the behaviour is the one that already existed, relocated so the
  - panel is the single home for what concerns the whole board.
  -
  - The board PUT wants name and colour alongside the tags, so they ride along
  - unchanged — this component never renames or recolours the board itself.
  -
  - @author Alain Lauzon <alauzon@alainlauzon.com>
  - @generated Claude (Opus 4.8)
  -->
<template>
	<div class="sk-palette">
		<p v-if="!rows.length" class="sk-palette-note">{{ t('Aucune étiquette dans la palette.') }}</p>

		<div v-for="(row, i) in rows" :key="i" class="sk-palette-row">
			<input v-model="row.color" type="color" :aria-label="t('Couleur')">
			<input v-model="row.name" type="text" :placeholder="t('nom de l\'étiquette')">
			<NcButton type="tertiary" :aria-label="t('Retirer de la palette')" :title="t('Retirer de la palette')" @click="rows.splice(i, 1)">
				✕
			</NcButton>
		</div>

		<div class="sk-palette-acts">
			<NcButton type="tertiary" @click="rows.push({ name: '', color: '#0082c9' })">
				{{ t('+ Étiquette') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || !dirty" @click="save">
				{{ saving ? t('Enregistrement…') : t('Enregistrer') }}
			</NcButton>
		</div>

		<p v-if="error" class="sk-palette-error">{{ error }}</p>
		<p class="sk-palette-note">
			{{ t('Une étiquette posée sur une carte mais absente de la palette reste affichée : elle n’est jamais retirée des cartes en douce.') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'

export default {
	name: 'TagPalette',

	components: { NcButton },

	props: {
		board: { type: Object, required: true },
	},

	emits: ['saved'],

	data() {
		return {
			rows: (this.board.tags || []).map((t) => ({ name: t.name, color: t.color || '#0082c9' })),
			saving: false,
			error: '',
		}
	},

	computed: {
		dirty() {
			const clean = (list) => JSON.stringify((list || []).map((t) => [t.name, t.color]))
			return clean(this.rows) !== clean(this.board.tags)
		},
	},

	methods: {
		t(s) {
			return s
		},

		async save() {
			this.saving = true
			this.error = ''
			try {
				await axios.put(
					generateUrl('/apps/sovereign-kanban-md-persistence/api/v1/boards/' + encodeURIComponent(this.board.id)),
					{
						name: this.board.name,
						color: this.board.color,
						tags: this.rows
							.filter((t) => t.name.trim())
							.map((t) => ({ name: t.name.trim(), color: t.color })),
						// The rev we read: a stale palette save is refused, not applied
						// over someone else's concurrent tag edit (Nisha, e0442c).
						baseRev: this.board.rev,
					},
				)
				this.$emit('saved')
			} catch (e) {
				if (e.response && e.response.status === 409 && e.response.data && e.response.data.error === 'conflict') {
					this.error = this.t('La palette a été modifiée entre-temps. Recharge le tableau pour repartir de l’état à jour.')
					this.$emit('saved')
				} else {
					this.error = this.t('Erreur à l’enregistrement de la palette.')
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.sk-palette-row {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 4px;
}

.sk-palette-row > input[type="text"] {
	flex: 1 1 auto;
	min-width: 0;
}

.sk-palette-row > input[type="color"] {
	flex: 0 0 auto;
	width: 34px;
	padding: 2px;
}

.sk-palette-acts {
	display: flex;
	gap: 6px;
	margin-top: 8px;
}

.sk-palette-note {
	margin: 12px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.sk-palette-error {
	margin: 8px 0 0;
	color: var(--color-error);
}
</style>
