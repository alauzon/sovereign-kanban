<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - Board create/edit modal in Vue — the migration of the vanilla showBoardForm
  - (Alain, 2026-07-18: "il manque le menu par tableau"). Name + colour + tag
  - palette are saved on submit (PUT/POST); columns are edited live with atomic
  - API calls (add/rename/remove/reorder), mirroring the vanilla behaviour.
  -
  - Sharing is NOT here yet — it is the NcSelect-users step (D12), a larger piece;
  - until then a board is shared through the vanilla app.
-->
<template>
	<NcModal size="normal" @close="$emit('close')">
		<div class="sk-boardedit">
			<h3 class="sk-boardedit-title">{{ isCreate ? t('Nouveau tableau') : t('Éditer le tableau') }}</h3>

			<label class="sk-field">
				<span>{{ t('Nom du tableau') }}</span>
				<input
					ref="nameInput"
					v-model="name"
					type="text"
					:placeholder="t('Nom du tableau')"
					@keyup.enter="submit">
			</label>

			<label class="sk-field sk-field--color">
				<span>{{ t('Couleur') }}</span>
				<input v-model="color" type="color">
			</label>

			<p v-if="!isCreate" class="sk-boardedit-hint">
				{{ t('Les listes se gèrent directement sur le tableau (＋ Liste, glisser pour réordonner, ✎ / ✕ sur chaque liste).') }}
			</p>

			<!-- Sharing: owner only (a board shared *with* me can't be reshared). -->

			<p v-if="error" class="sk-boardedit-error">{{ error }}</p>

			<div class="sk-boardedit-actions">
				<NcButton
					v-if="!isCreate"
					type="error"
					:disabled="busy"
					@click="destroy">
					{{ t('Supprimer le tableau') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || !name.trim()"
					@click="submit">
					{{ isCreate ? t('Créer') : t('Enregistrer') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'

const BASE = '/apps/sovereign-kanban-md-persistence/api/v1/boards'

export default {
	name: 'BoardEditModal',

	components: { NcModal, NcButton },

	props: {
		// null → create; a board object → edit.
		board: { type: Object, default: null },
	},

	emits: ['saved', 'deleted', 'refresh', 'close'],

	data() {
		return {
			name: this.board ? this.board.name : '',
			color: (this.board && this.board.color) || '#0082c9',
			palette: (this.board && this.board.tags ? this.board.tags : []).map((t) => ({
				name: t.name,
				color: t.color || '#0082c9',
			})),
			saving: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		isCreate() {
			return !this.board
		},
	},

	mounted() {
		this.$nextTick(() => this.$refs.nameInput && this.$refs.nameInput.focus())
	},

	methods: {
		t(s) {
			return s
		},

		boardUrl() {
			return generateUrl(BASE + '/' + encodeURIComponent(this.board.id))
		},

		async submit() {
			const name = this.name.trim()
			if (!name) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const payload = { name, color: this.color }
				if (!this.isCreate) {
					// The palette is edited in the board panel now; send it back
					// untouched so saving a rename never drops a label.
					payload.tags = this.board && this.board.tags ? this.board.tags : []
					// The rev we read, so a stale save is refused instead of erasing a
					// concurrent change (Nisha, e0442c).
					payload.baseRev = this.board ? this.board.rev : null
				}
				const res = this.isCreate
					? await axios.post(generateUrl(BASE), payload)
					: await axios.put(this.boardUrl(), payload)
				this.$emit('saved', res.data && res.data.board ? res.data.board.id : null)
			} catch (e) {
				const status = e.response && e.response.status
				const code = e.response && e.response.data && e.response.data.error
				if (status === 409 && code === 'conflict') {
					// Someone changed the board while this editor was open. Refresh the
					// list and tell the user to reopen from the current state.
					this.error = this.t('Le tableau a été modifié entre-temps. Ferme et rouvre l\'éditeur pour repartir de l\'état à jour.')
					this.$emit('refresh')
				} else if (status === 409) {
					this.error = this.t('Un tableau porte déjà ce nom.')
				} else {
					this.error = this.t('Erreur à l\'enregistrement.')
				}
			} finally {
				this.saving = false
			}
		},

		async destroy() {
			if (!window.confirm(this.t('Supprimer le tableau « ') + this.board.name + this.t(' » et son contenu ?'))) {
				return
			}
			this.busy = true
			try {
				await axios.delete(this.boardUrl())
				this.$emit('deleted')
			} catch (e) {
				this.error = this.t('Erreur à la suppression.')
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.sk-boardedit {
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 420px;
}

.sk-boardedit-title {
	margin: 0;
}

.sk-boardedit-hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sk-field > span {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-field > input[type="text"] {
	width: 100%;
	box-sizing: border-box;
}

.sk-field--color > input {
	width: 60px;
	height: 32px;
	padding: 0;
}

.sk-col-row,
.sk-col-add,
.sk-palette-row {
	display: flex;
	gap: 4px;
	align-items: center;
}

.sk-col-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	padding: 0 2px;
	user-select: none;
}

.sk-col-row--drag {
	opacity: 0.5;
}

.sk-col-row > input[type="text"],
.sk-col-add > input,
.sk-palette-row > input[type="text"] {
	flex: 1 1 auto;
	min-width: 0;
}

.sk-boardedit-error {
	color: var(--color-error);
	margin: 0;
}

.sk-boardedit-actions {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	margin-top: 8px;
}
</style>
