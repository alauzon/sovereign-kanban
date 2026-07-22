<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - Board sharing in Vue — migration of the vanilla renderSharePanel (owner only).
  - Lists the current shares with a revoke button and a small add form, backed by
  - GET/POST/DELETE /boards/{id}/shares. The recipient field is an NcSelect fed by
  - GET /sharees, showing avatar · name · email for accounts like Nextcloud's own
  - share dialog (Steve, 2026-07-20, carte e85179) — the datalist showed the raw id.
-->
<template>
	<section class="sk-share-panel">
		<span class="sk-field-label">{{ t('Partage') }}</span>

		<div class="sk-share-list">
			<p v-if="loading" class="sk-field-hint">{{ t('Chargement…') }}</p>
			<p v-else-if="!shares.length" class="sk-field-hint">{{ t('Pas encore partagé.') }}</p>
			<div v-for="s in shares" :key="s.id" class="sk-share-row">
				<span>{{ kindLabel(s.type) }} · {{ s.label || s.with }} · {{ s.permissions > 1 ? t('collaboration') : t('lecture') }}</span>
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
			<NcSelect
				class="sk-sharee-select"
				:options="sharees"
				:model-value="selectedSharee"
				:user-select="type === 'user'"
				:filterable="false"
				label="label"
				input-label=""
				:placeholder="t('Usager ou groupe…')"
				:aria-label-combobox="t('Usager ou groupe avec qui partager')"
				@search="onSearch"
				@option:selected="onPick">
				<template #option="option">
					<span class="sk-sharee-opt">
						<NcAvatar
							v-if="option.type === 'user'"
							:user="option.id"
							:size="24"
							:hide-status="true"
							:disable-menu="true" />
						<span v-else class="sk-sharee-glyph" aria-hidden="true">{{ option.type === 'group' ? '👥' : '🔵' }}</span>
						<span class="sk-sharee-text">
							<span class="sk-sharee-name">{{ option.label }}</span>
							<span v-if="option.email" class="sk-sharee-email">{{ option.email }}</span>
						</span>
					</span>
				</template>
			</NcSelect>
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
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'

const BASE = '/apps/sovereign-kanban-md-persistence/api/v1'

export default {
	name: 'SharePanel',

	components: { NcAvatar, NcButton, NcSelect },

	props: {
		boardId: { type: String, required: true },
	},

	data() {
		return {
			shares: [],
			sharees: [],
			type: 'user',
			shareWith: '',
			selectedSharee: null,
			level: 'read',
			loading: true,
			busy: false,
			error: '',
			searchTimer: null,
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
			this.selectedSharee = null
			this.shareWith = ''
		},

		// NcSelect emits the raw query string on @search. Debounced server lookup
		// (the results carry avatar/name/email — filtering is server-side, so the
		// component's own filter is off via :filterable="false").
		onSearch(query) {
			const val = (query || '').trim()
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
					// displayName + user let NcSelect's user-select render the avatar and
					// name on its own; the #option template adds the email on top.
					this.sharees = (res.data.sharees || []).map((sh) => ({
						...sh,
						displayName: sh.label,
						user: sh.type === 'user' ? sh.id : undefined,
					}))
				} catch (e) {
					// Autocomplete is best-effort; a failed lookup just yields no hints.
				}
			}, 250)
		},

		// A suggestion was picked: the id is what we share with, the object is what
		// the field shows (name, not the raw handle — Steve's whole complaint).
		onPick(option) {
			this.selectedSharee = option || null
			this.shareWith = option ? option.id : ''
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
				this.selectedSharee = null
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

.sk-sharee-select {
	flex: 1 1 180px;
	min-width: 0;
}

.sk-sharee-opt {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.sk-sharee-glyph {
	flex: 0 0 auto;
	width: 24px;
	text-align: center;
}

.sk-sharee-text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.sk-sharee-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sk-sharee-email {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
