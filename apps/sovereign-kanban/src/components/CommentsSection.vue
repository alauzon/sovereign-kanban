<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - Card comments in Vue — migration of the vanilla loadComments/renderAddComment.
  - List newest-first (author, date, server-sanitized HTML body), add via the
  - rich Text editor (textarea fallback), delete. Backed by GET/POST/DELETE
  - /boards/{id}/cards/{cardId}/comments. Read-only hides the write actions.
  -
  - Inline editing (click a body to edit, PUT) is a later step; add/list/delete
  - is the core.
-->
<template>
	<section class="sk-comments">
		<h4 class="sk-comments-title">{{ t('Commentaires') }}</h4>

		<div v-if="!readOnly" class="sk-comment-add">
			<div
				v-if="!adding"
				class="sk-comment-addph"
				role="button"
				tabindex="0"
				@click="startAdd"
				@keyup.enter="startAdd">
				{{ t('Ajouter un commentaire…') }}
			</div>
			<div v-else class="sk-comment-editwrap">
				<div v-show="editorMounted" ref="editorEl" class="sk-comment-editor" />
				<textarea
					v-show="!editorMounted"
					v-model="draft"
					class="sk-comment-input"
					rows="3"
					:placeholder="t('Commentaire (Markdown)…')" />
				<div v-if="members.length" class="sk-comment-mentionrow">
					<NcSelect
						class="sk-comment-mention"
						:options="members"
						:model-value="mentionValue"
						:user-select="true"
						:filterable="true"
						label="label"
						input-label=""
						:placeholder="t('Mentionner un membre…')"
						:aria-label-combobox="t('Mentionner un membre du tableau')"
						@option:selected="mention">
						<template #option="option">
							<span class="sk-mention-opt">
								<NcAvatar :user="option.id" :size="24" :hide-status="true" :disable-menu="true" />
								<span class="sk-mention-name">{{ option.label }}</span>
							</span>
						</template>
					</NcSelect>
				</div>
				<div class="sk-comment-editactions">
					<NcButton type="primary" :disabled="posting" @click="submit">
						{{ t('Commenter') }}
					</NcButton>
					<NcButton :disabled="posting" @click="cancelAdd">
						{{ t('Annuler') }}
					</NcButton>
				</div>
			</div>
		</div>

		<p v-if="!comments.length" class="sk-loading">{{ t('Aucun commentaire.') }}</p>
		<div v-for="c in comments" :key="c.id" class="sk-comment">
			<div class="sk-comment-meta">
				<span>{{ c.author }} — {{ formatDate(c.created_at) }}</span>
				<span v-if="!readOnly && editingId !== c.id" class="sk-comment-actions">
					<button class="sk-comment-act" :aria-label="t('Modifier ce commentaire')" :title="t('Modifier ce commentaire')" @click="startEdit(c)">✎</button>
					<button class="sk-comment-del" :aria-label="t('Supprimer ce commentaire')" :title="t('Supprimer ce commentaire')" @click="remove(c)">✕</button>
				</span>
			</div>
			<template v-if="editingId === c.id">
				<textarea
					v-model="editDraft"
					class="sk-comment-input"
					rows="3"
					@keyup.esc="cancelEdit" />
				<div class="sk-comment-editactions">
					<NcButton type="primary" :disabled="savingEdit" @click="saveEdit(c)">
						{{ t('Enregistrer') }}
					</NcButton>
					<NcButton :disabled="savingEdit" @click="cancelEdit">
						{{ t('Annuler') }}
					</NcButton>
				</div>
			</template>
			<template v-else>
				<!-- Body HTML is sanitized server-side (same as the vanilla app). -->
				<!-- eslint-disable-next-line vue/no-v-html -->
				<div v-if="c.body_html" class="sk-comment-body sk-rich" v-html="c.body_html" />
				<div v-else class="sk-comment-body">{{ c.body }}</div>
			</template>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { loadTextEditor } from '../text-editor.js'

export default {
	name: 'CommentsSection',

	components: { NcAvatar, NcButton, NcSelect },

	props: {
		boardId: { type: String, required: true },
		cardId: { type: String, required: true },
		readOnly: { type: Boolean, default: false },
	},

	data() {
		return {
			comments: [],
			adding: false,
			draft: '',
			posting: false,
			editorMounted: false,
			editorInstance: null,
			editingId: null,
			editDraft: '',
			savingEdit: false,
			// Board members for the @mention picker ({id: uid, label: displayName}).
			// The NC rich editor won't feed custom @ suggestions in our mode, so we
			// offer them through a picker instead (Alain, 2026-07-22, option B).
			members: [],
			mentionValue: null,
		}
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		this.destroyEditor()
	},

	methods: {
		t(s) {
			return s
		},

		formatDate(iso) {
			try {
				return new Date(iso).toLocaleString('fr-CA')
			} catch (e) {
				return iso
			}
		},

		commentsUrl() {
			return generateUrl(
				'/apps/sovereign-kanban-md-persistence/api/v1/boards/'
				+ encodeURIComponent(this.boardId) + '/cards/' + encodeURIComponent(this.cardId) + '/comments',
			)
		},

		async load() {
			try {
				const res = await axios.get(this.commentsUrl())
				// Newest first.
				this.comments = (res.data.comments || []).slice().reverse()
			} catch (e) {
				this.comments = []
			}
		},

		async startAdd() {
			this.adding = true
			this.draft = ''
			this.loadMembers()
			await this.$nextTick()
			const createEditor = await loadTextEditor()
			if (!createEditor || !this.$refs.editorEl) {
				this.editorMounted = false
				return
			}
			try {
				const inst = await createEditor({
					el: this.$refs.editorEl,
					content: '',
					useSession: false,
					autofocus: true,
					onUpdate: (data) => {
						this.draft = data.markdown
					},
				})
				this.editorInstance = inst
				this.editorMounted = true
			} catch (e) {
				this.editorMounted = false
			}
		},

		// The board members a mention can reach (the notifier's access set). Fetched
		// once per add so the picker only ever offers someone who would be notified.
		async loadMembers() {
			if (this.members.length) {
				return
			}
			try {
				const res = await axios.get(generateUrl(
					'/apps/sovereign-kanban-md-persistence/api/v1/boards/'
					+ encodeURIComponent(this.boardId) + '/members',
				))
				this.members = (res.data.members || []).map((m) => ({ id: m.uid, label: m.displayName }))
			} catch (e) {
				this.members = []
			}
		},

		// Insert a mention where the cursor is. In the rich editor we insert a real
		// mention node ({id,label}) — it renders as a chip and serializes to the
		// canonical @[label](mention://user/uid) the server parses. Textarea fallback:
		// append that same markdown. Reset the picker so it shows the placeholder again.
		mention(option) {
			if (!option || !option.id) {
				this.mentionValue = null
				return
			}
			const canonical = '@[' + option.label + '](mention://user/' + encodeURIComponent(option.id) + ') '
			let inserted = false
			if (this.editorMounted && this.editorInstance && typeof this.editorInstance.insertAtCursor === 'function') {
				try {
					this.editorInstance.insertAtCursor([
						{ type: 'mention', attrs: { id: option.id, label: option.label } },
						{ type: 'text', text: ' ' },
					])
					inserted = true
				} catch (e) {
					inserted = false
				}
			}
			if (!inserted) {
				this.draft = (this.draft ? this.draft.replace(/\s*$/, '') + ' ' : '') + canonical
			}
			this.mentionValue = null
		},

		async cancelAdd() {
			await this.destroyEditor()
			this.adding = false
			this.draft = ''
		},

		async destroyEditor() {
			if (this.editorInstance && typeof this.editorInstance.destroy === 'function') {
				try {
					await this.editorInstance.destroy()
				} catch (e) {
					// ignore
				}
			}
			this.editorInstance = null
			this.editorMounted = false
		},

		async submit() {
			const body = (this.draft || '').trim()
			if (!body) {
				return
			}
			this.posting = true
			try {
				await axios.post(this.commentsUrl(), { body })
				await this.cancelAdd()
				await this.load()
			} catch (e) {
				// leave the draft so the author can retry
			} finally {
				this.posting = false
			}
		},

		startEdit(comment) {
			this.editingId = comment.id
			this.editDraft = comment.body || ''
		},

		cancelEdit() {
			this.editingId = null
			this.editDraft = ''
		},

		async saveEdit(comment) {
			const body = (this.editDraft || '').trim()
			if (!body) {
				return
			}
			this.savingEdit = true
			try {
				await axios.put(this.commentsUrl() + '/' + encodeURIComponent(comment.id), { body })
				this.cancelEdit()
				await this.load()
			} catch (e) {
				// leave the editor open so the author can retry
			} finally {
				this.savingEdit = false
			}
		},

		async remove(comment) {
			if (!window.confirm(this.t('Supprimer ce commentaire ?'))) {
				return
			}
			try {
				await axios.delete(this.commentsUrl() + '/' + encodeURIComponent(comment.id))
				await this.load()
			} catch (e) {
				// ignore; a failed delete leaves the comment in place
			}
		},
	},
}
</script>

<style scoped>
.sk-comments {
	margin-top: 12px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sk-comments-title {
	margin: 0;
}

.sk-comment-addph {
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	padding: 6px 10px;
	cursor: text;
}

.sk-comment-editor {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	min-height: 80px;
	padding: 4px 8px;
}

.sk-comment-input {
	width: 100%;
	box-sizing: border-box;
}

.sk-comment-mentionrow {
	margin-top: 6px;
	max-width: 320px;
}

.sk-comment-mention {
	width: 100%;
}

.sk-mention-opt {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.sk-mention-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sk-comment-editactions {
	display: flex;
	gap: 8px;
	margin-top: 6px;
}

.sk-comment {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	padding: 8px 10px;
}

.sk-comment-meta {
	display: flex;
	justify-content: space-between;
	align-items: center;
	color: var(--color-text-maxcontrast);
	font-size: 90%;
	margin-bottom: 4px;
}

.sk-comment-actions {
	display: flex;
	gap: 4px;
}

.sk-comment-act {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.sk-comment-del {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-error);
}

.sk-comment-body {
	overflow-wrap: anywhere;
}
</style>
