<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - Board sharing in Vue — migration of the vanilla renderSharePanel (owner only).
  - Lists the current shares with a revoke button and a small add form, backed by
  - GET/POST/DELETE /boards/{id}/shares. The recipient field autocompletes from
  - GET /sharees, the TYPE selector driving the suggestions (a datalist, same as
  - the vanilla app — portable, no extra dependency).
-->
<template>
	<section class="sk-share-panel">
		<span class="sk-field-label">{{ t('Partage') }}</span>

		<div class="sk-share-list">
			<p v-if="loading" class="sk-field-hint">{{ t('Chargement…') }}</p>
			<p v-else-if="!shares.length" class="sk-field-hint">{{ t('Pas encore partagé.') }}</p>
			<div v-for="s in shares" :key="s.id" class="sk-share-row">
				<span>{{ kindLabel(s.type) }} · {{ s.with }} · {{ s.permissions > 1 ? t('collaboration') : t('lecture') }}</span>
				<NcButton type="error" :aria-label="t('Révoquer')" :disabled="busy" @click="revoke(s)">
					✕
				</NcButton>
			</div>
		</div>

		<div class="sk-share-add">
			<select v-model="type" :aria-label="t('Type de destinataire')" @change="onTypeChange">
				<option value="user">{{ t('Personne') }}</option>
				<option value="group">{{ t('Groupe') }}</option>
				<option value="team">{{ t('Équipe') }}</option>
			</select>
			<input
				v-model="shareWith"
				type="text"
				:placeholder="t('Usager ou groupe…')"
				:aria-label="t('Usager ou groupe avec qui partager')"
				:list="listId"
				@input="onSearch">
			<datalist :id="listId">
				<option v-for="s in sharees" :key="s.id" :value="s.id" :label="s.label" />
			</datalist>
			<select v-model="level" :aria-label="t('Niveau de partage')">
				<option value="read">{{ t('Lecture seule') }}</option>
				<option value="collaborate">{{ t('Collaboration') }}</option>
			</select>
			<NcButton :disabled="busy || !shareWith.trim()" @click="add">
				{{ t('Partager') }}
			</NcButton>
		</div>

		<p v-if="error" class="sk-share-error">{{ error }}</p>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'

const BASE = '/apps/sovereign-kanban-md-persistence/api/v1'

export default {
	name: 'SharePanel',

	components: { NcButton },

	props: {
		boardId: { type: String, required: true },
	},

	data() {
		return {
			shares: [],
			sharees: [],
			type: 'user',
			shareWith: '',
			level: 'read',
			loading: true,
			busy: false,
			error: '',
			searchTimer: null,
			listId: 'sk-sharees-' + this.boardId,
		}
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	methods: {
		t(s) {
			return s
		},

		kindLabel(kind) {
			return { user: this.t('personne'), group: this.t('groupe'), team: this.t('équipe') }[kind] || kind
		},

		sharesUrl() {
			return generateUrl(BASE + '/boards/' + encodeURIComponent(this.boardId) + '/shares')
		},

		async load() {
			this.loading = true
			try {
				const res = await axios.get(this.sharesUrl())
				this.shares = res.data.shares || []
			} catch (e) {
				this.error = this.t('Impossible de charger les partages.')
			} finally {
				this.loading = false
			}
		},

		onTypeChange() {
			this.sharees = []
		},

		onSearch() {
			const val = this.shareWith.trim()
			if (this.sharees.some((s) => s.id === val)) {
				return
			}
			clearTimeout(this.searchTimer)
			if (val.length < 2) {
				this.sharees = []
				return
			}
			this.searchTimer = setTimeout(async () => {
				try {
					const res = await axios.get(generateUrl(BASE + '/sharees'), {
						params: { search: val, type: this.type },
					})
					this.sharees = res.data.sharees || []
				} catch (e) {
					// Autocomplete is best-effort; a failed lookup just yields no hints.
				}
			}, 250)
		},

		async add() {
			const shareWith = this.shareWith.trim()
			if (!shareWith) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(this.sharesUrl(), {
					shareType: this.type,
					shareWith,
					level: this.level,
				})
				this.shareWith = ''
				this.sharees = []
				await this.load()
			} catch (e) {
				const status = e.response && e.response.status
				this.error = status === 403
					? this.t('Seul le propriétaire peut partager ce tableau.')
					: status === 400
						? this.t('Destinataire ou type invalide.')
						: this.t('Erreur au partage.')
			} finally {
				this.busy = false
			}
		},

		async revoke(share) {
			this.busy = true
			this.error = ''
			try {
				await axios.delete(this.sharesUrl() + '/' + encodeURIComponent(share.id))
				await this.load()
			} catch (e) {
				this.error = this.t('Erreur à la révocation.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.sk-share-panel {
	display: flex;
	flex-direction: column;
	gap: 6px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.sk-field-label {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-field-hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.sk-share-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.sk-share-add {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	align-items: center;
}

.sk-share-add > input {
	flex: 1 1 140px;
	min-width: 0;
}

.sk-share-error {
	color: var(--color-error);
	margin: 0;
}
</style>
