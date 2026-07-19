<!--
  Card attachments (Alain, 2026-07-19): the files in the card's attachments/ folder.
  Upload is base64 JSON (capped 10 MiB server-side); the folder is the source of
  truth — nothing mirrored in the frontmatter. Download carries the session cookie.
-->
<template>
	<div class="sk-attachments">
		<div v-if="!readOnly" class="sk-att-head">
			<button type="button" class="sk-att-add" :disabled="uploading" @click="pick">
				{{ uploading ? t('Envoi…') : t('＋ Ajouter un fichier') }}
			</button>
			<input ref="file" type="file" class="sk-att-input" @change="onFile">
		</div>

		<p v-if="loading" class="sk-att-empty">{{ t('Chargement…') }}</p>
		<p v-else-if="!items.length" class="sk-att-empty">{{ t('Aucune pièce jointe.') }}</p>
		<ul v-else class="sk-att-list">
			<li v-for="att in items" :key="att.name" class="sk-att-item">
				<a :href="downloadUrl(att.name)" class="sk-att-name" :download="att.name">📎 {{ att.name }}</a>
				<span class="sk-att-size">{{ humanSize(att.size) }}</span>
				<button
					v-if="!readOnly"
					type="button"
					class="sk-att-remove"
					:aria-label="t('Supprimer la pièce jointe')"
					:title="t('Supprimer la pièce jointe')"
					@click="remove(att.name)">
					✕
				</button>
			</li>
		</ul>

		<p v-if="error" class="sk-att-error">{{ error }}</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const MAX_BYTES = 10 * 1024 * 1024

export default {
	name: 'AttachmentsSection',

	props: {
		boardId: { type: String, required: true },
		cardId: { type: String, required: true },
		readOnly: { type: Boolean, default: false },
	},

	emits: ['changed'],

	data() {
		return {
			items: [],
			loading: true,
			uploading: false,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t(s) {
			return s
		},

		base() {
			return generateUrl(
				'/apps/sovereign-kanban-md-persistence/api/v1/boards/'
				+ encodeURIComponent(this.boardId) + '/cards/' + encodeURIComponent(this.cardId) + '/attachments',
			)
		},

		downloadUrl(name) {
			return this.base() + '/' + encodeURIComponent(name)
		},

		async load() {
			this.loading = true
			try {
				const res = await axios.get(this.base())
				this.items = res.data.attachments || []
			} catch (e) {
				this.items = []
			} finally {
				this.loading = false
			}
		},

		pick() {
			this.$refs.file.click()
		},

		onFile(ev) {
			const file = ev.target.files && ev.target.files[0]
			if (!file) {
				return
			}
			this.error = ''
			if (file.size > MAX_BYTES) {
				this.error = this.t('Fichier trop volumineux (max 10 Mo).')
				this.$refs.file.value = ''
				return
			}
			const reader = new FileReader()
			reader.onload = () => this.upload(file.name, String(reader.result).split(',')[1] || '')
			reader.onerror = () => { this.error = this.t('Lecture du fichier impossible.') }
			reader.readAsDataURL(file)
		},

		async upload(name, base64) {
			this.uploading = true
			this.error = ''
			try {
				const res = await axios.post(this.base(), { name, content_base64: base64 })
				this.items = res.data.attachments || []
				this.$emit('changed')
			} catch (e) {
				this.error = this.t('Envoi impossible. Rafraîchissez (F5) si la session a expiré.')
			} finally {
				this.uploading = false
				if (this.$refs.file) {
					this.$refs.file.value = ''
				}
			}
		},

		async remove(name) {
			this.error = ''
			try {
				const res = await axios.delete(this.downloadUrl(name))
				this.items = res.data.attachments || []
				this.$emit('changed')
			} catch (e) {
				this.error = this.t('Suppression impossible.')
			}
		},

		humanSize(bytes) {
			if (bytes == null) {
				return ''
			}
			if (bytes < 1024) {
				return bytes + ' o'
			}
			if (bytes < 1024 * 1024) {
				return (bytes / 1024).toFixed(1) + ' Ko'
			}
			return (bytes / (1024 * 1024)).toFixed(1) + ' Mo'
		},
	},
}
</script>

<style scoped>
.sk-attachments {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sk-att-input {
	display: none;
}

.sk-att-add {
	background: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	color: var(--color-primary-element);
	cursor: pointer;
	padding: 4px 10px;
}

.sk-att-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sk-att-item {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sk-att-name {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sk-att-size {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	font-size: 85%;
}

.sk-att-remove {
	flex: 0 0 auto;
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.sk-att-empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-att-error {
	margin: 0;
	color: var(--color-error, #e9322d);
	font-size: 90%;
}
</style>
