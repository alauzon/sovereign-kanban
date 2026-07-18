<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - Card editor in Vue (migration of the vanilla renderDetail). Title, dates,
  - assignees, priority, phase, tags, body, save/delete. Behind the
  - characterization test.
  -
  - Dates use <input type="datetime-local"> with the SAME contract as the vanilla
  - fix (2026-07-15/18): a stored date may be 'Y-m-d' (no time) or 'Y-m-dTHH:MM';
  - midnight in the picker means "no time" and round-trips back to a plain date,
  - so a card without a time never gets one. The server validates (400 on a
  - malformed date — Steve's 6-digit-year bug), so a rejected save surfaces here.
  -
  - The body is a plain textarea for now; Nextcloud's Text editor (D1) is a later
  - step. Read-only disables every write control — Steve's bare-403, in Vue.
-->
<template>
	<NcModal :size="expanded ? 'full' : 'normal'" @close="$emit('close')">
		<div class="sk-detail-vue" :class="{ 'sk-detail-vue--expanded': expanded }">
			<div class="sk-detail-toolbar">
				<NcButton
					type="tertiary"
					:aria-label="expanded ? t('Réduire l\'éditeur') : t('Agrandir l\'éditeur')"
					@click="expanded = !expanded">
					{{ expanded ? t('⤡ Réduire') : t('⤢ Plein écran') }}
				</NcButton>
			</div>

			<input
				v-model="form.title"
				class="sk-detail-title-input"
				type="text"
				:readonly="readOnly"
				:placeholder="t('Titre')">

			<div v-if="readOnly" class="sk-readonly-banner">
				👁 {{ t('Lecture seule — vous ne pouvez pas modifier cette carte.') }}
			</div>

			<label class="sk-field">
				<span>{{ t('Date de début') }}</span>
				<input v-model="startInput" type="datetime-local" :disabled="readOnly">
			</label>

			<label class="sk-field">
				<span>{{ t('Date de fin') }}</span>
				<input v-model="dueInput" type="datetime-local" :disabled="readOnly">
			</label>

			<label class="sk-field">
				<span>{{ t('Assignés (séparés par des virgules)') }}</span>
				<input v-model="assigneesInput" type="text" :disabled="readOnly" placeholder="alain, steve">
			</label>

			<label class="sk-field">
				<span>{{ t('Priorité') }}</span>
				<select v-model="form.priority" :disabled="readOnly">
					<option value="">{{ t('—') }}</option>
					<option v-for="p in priorities" :key="p" :value="p">{{ p }}</option>
				</select>
			</label>

			<label class="sk-field">
				<span>{{ t('Phase') }}</span>
				<select v-model="form.phase" :disabled="readOnly">
					<option value="">{{ t('—') }}</option>
					<option v-for="p in phases" :key="p" :value="p">{{ t('Phase') }} {{ p }}</option>
				</select>
			</label>

			<label class="sk-field">
				<span>{{ t('Étiquettes (séparées par des virgules)') }}</span>
				<input v-model="tagsInput" type="text" :disabled="readOnly" list="sk-known-tags">
				<datalist id="sk-known-tags">
					<option v-for="tag in knownTags" :key="tag" :value="tag" />
				</datalist>
				<div v-if="tagSuggestions.length && !readOnly" class="sk-tag-suggestions">
					<button
						v-for="tag in tagSuggestions"
						:key="tag"
						type="button"
						class="sk-tag-suggestion"
						@click="addTag(tag)">+ {{ tag }}</button>
				</div>
			</label>

			<label class="sk-field">
				<span>{{ t('Description') }}</span>
				<textarea v-model="form.description" rows="6" :readonly="readOnly" />
			</label>

			<p v-if="error" class="sk-detail-error">{{ error }}</p>

			<div class="sk-detail-actions">
				<NcButton v-if="!readOnly" type="error" @click="remove">
					{{ t('Supprimer') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="readOnly || saving"
					@click="save">
					{{ t('Enregistrer') }}
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

export default {
	name: 'CardDetail',

	components: { NcModal, NcButton },

	props: {
		boardId: { type: String, required: true },
		card: { type: Object, required: true },
		readOnly: { type: Boolean, default: false },
		// Every tag already used on the board, for suggestion (Alain, 2026-07-18).
		knownTags: { type: Array, default: () => [] },
	},

	emits: ['saved', 'deleted', 'close'],

	data() {
		return {
			form: {
				title: this.card.title || '',
				description: this.card.description || '',
				priority: this.card.priority || '',
				phase: this.card.phase != null ? String(this.card.phase) : '',
			},
			startInput: this.toInput(this.card.start_date),
			dueInput: this.toInput(this.card.due_date),
			assigneesInput: (this.card.assignees || []).join(', '),
			tagsInput: (this.card.tags || []).join(', '),
			priorities: ['1', '2', '3', '4', '5'],
			phases: ['1', '2', '3', '4'],
			saving: false,
			error: '',
			expanded: false,
		}
	},

	computed: {
		// Known tags not already typed in the field — the ones worth suggesting.
		tagSuggestions() {
			const current = new Set(this.splitList(this.tagsInput).map((t) => t.toLowerCase()))
			return this.knownTags.filter((tag) => !current.has(String(tag).toLowerCase()))
		},
	},

	methods: {
		t(s) {
			return s
		},

		// Append a suggested tag to the comma-separated field.
		addTag(tag) {
			const list = this.splitList(this.tagsInput)
			list.push(tag)
			this.tagsInput = list.join(', ')
		},

		// 'Y-m-d' → 'Y-m-dT00:00' (the picker needs a time); a full date-time is
		// passed through. Empty stays empty.
		toInput(value) {
			if (!value) {
				return ''
			}
			return value.length === 10 ? value + 'T00:00' : value
		},

		// Midnight means "no time was chosen" → store a plain date; else keep it.
		fromInput(value) {
			if (!value) {
				return ''
			}
			return value.endsWith('T00:00') ? value.slice(0, 10) : value
		},

		splitList(s) {
			return s.split(',').map((x) => x.trim()).filter(Boolean)
		},

		url() {
			return generateUrl(
				'/apps/sovereign-kanban-md-persistence/api/v1/boards/'
				+ encodeURIComponent(this.boardId) + '/cards/' + encodeURIComponent(this.card.id),
			)
		},

		async save() {
			this.saving = true
			this.error = ''
			try {
				await axios.put(this.url(), {
					title: this.form.title.trim(),
					description: this.form.description,
					start_date: this.fromInput(this.startInput),
					due_date: this.fromInput(this.dueInput),
					assignees: this.splitList(this.assigneesInput),
					priority: this.form.priority,
					tags: this.splitList(this.tagsInput),
					phase: this.form.phase,
				})
				this.$emit('saved')
			} catch (e) {
				const code = e.response && e.response.data && e.response.data.error
				this.error = code === 'invalid_date'
					? this.t('Date invalide.')
					: code === 'invalid_assignee'
						? this.t('Un assigné n\'existe pas comme compte.')
						: this.t('Erreur à l\'enregistrement.')
			} finally {
				this.saving = false
			}
		},

		async remove() {
			if (!window.confirm(this.t('Supprimer cette carte ?'))) {
				return
			}
			await axios.delete(this.url())
			this.$emit('deleted')
		},
	},
}
</script>

<style scoped>
.sk-detail-vue {
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 10px;
	min-width: 420px;
}

/* Plein écran (Alain, 2026-07-18): the modal takes the whole viewport, so the
   form gets the full width for editing long descriptions. */
.sk-detail-vue--expanded {
	min-width: 0;
	height: 100%;
}

.sk-detail-toolbar {
	display: flex;
	justify-content: flex-end;
}

/* The datetime-local picker draws its calendar indicator at the right edge; if
   the field keeps its narrow intrinsic width the indicator overlaps the value
   (Alain, 2026-07-18: last date digit hidden). Full width clears it, and gives
   the description its full width too. */
.sk-field > input,
.sk-field > select,
.sk-field > textarea {
	width: 100%;
	box-sizing: border-box;
}

/* In plein écran the description grows to fill the freed vertical space. */
.sk-detail-vue--expanded .sk-field:last-of-type {
	flex: 1 1 auto;
}

.sk-detail-vue--expanded .sk-field:last-of-type textarea {
	height: 100%;
	min-height: 200px;
	resize: vertical;
}

.sk-detail-title-input {
	font-size: 1.2em;
	font-weight: 600;
	width: 100%;
}

.sk-tag-suggestions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 4px;
}

.sk-tag-suggestion {
	font-size: 85%;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 1px 10px;
	cursor: pointer;
}

.sk-tag-suggestion:hover {
	background: var(--color-background-hover);
}

.sk-readonly-banner {
	padding: 6px 12px;
	border-radius: var(--border-radius, 8px);
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-element-text, #fff);
}

.sk-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.sk-field > span {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-detail-error {
	color: var(--color-error);
}

.sk-detail-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
