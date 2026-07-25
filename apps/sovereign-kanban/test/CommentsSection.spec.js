import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

// The @mention picker (Alain, 2026-07-22, option B): the NC rich editor won't feed
// custom @ suggestions in our mode, so a member picker inserts « @Display Name »
// instead. Into a real ProseMirror the insert goes through execCommand (e2e-tested,
// jsdom has no editor); here we pin the DRAFT fallback and the reset behaviour.
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('../src/text-editor.js', () => ({ loadTextEditor: vi.fn(() => Promise.resolve(null)) }))

import CommentsSection from '../src/components/CommentsSection.vue'

const stubs = { NcButton: true, NcSelect: true, NcAvatar: true }

function makeWrapper() {
	return mount(CommentsSection, {
		props: { boardId: 'b1', cardId: 'c1' },
		global: { stubs },
	})
}

const steve = { id: 'StevLauz', label: 'Steve Lauzier' }

beforeEach(() => vi.clearAllMocks())

describe('CommentsSection @mention picker', () => {
	it('appends « @Display Name » to an empty draft (no rich editor)', () => {
		const w = makeWrapper()
		w.vm.editorMounted = false
		w.vm.draft = ''
		w.vm.mention(steve)
		expect(w.vm.draft).toBe('@Steve Lauzier ')
		expect(w.vm.mentionValue).toBe(null)
	})

	it('appends after existing text with a single separating space', () => {
		const w = makeWrapper()
		w.vm.editorMounted = false
		w.vm.draft = 'merci'
		w.vm.mention(steve)
		expect(w.vm.draft).toBe('merci @Steve Lauzier ')
	})

	it('falls back to the draft when the editor has no ProseMirror element', () => {
		// editorMounted true but the rich editor never really mounted (jsdom): the
		// insert path finds no .ProseMirror and must not lose the mention.
		const w = makeWrapper()
		w.vm.editorMounted = true
		w.vm.draft = ''
		w.vm.mention(steve)
		expect(w.vm.draft).toBe('@Steve Lauzier ')
	})

	it('a null pick is a no-op that just resets the picker', () => {
		const w = makeWrapper()
		w.vm.draft = 'x'
		w.vm.mention(null)
		expect(w.vm.draft).toBe('x')
		expect(w.vm.mentionValue).toBe(null)
	})
})

describe('CommentsSection mention row visibility', () => {
	// A board shared to nobody used to HIDE the picker with no explanation —
	// indistinguishable from the team-share bug of 2026-07-25. The empty state
	// must say why, and only once the fetch has actually answered.
	async function startAdding(w) {
		w.vm.adding = true
		await w.vm.$nextTick()
	}

	it('says why when the fetch answered with nobody', async () => {
		const w = makeWrapper()
		await startAdding(w)
		w.vm.members = []
		w.vm.membersLoaded = true
		await w.vm.$nextTick()
		expect(w.find('.sk-comment-mentionempty').exists()).toBe(true)
		expect(w.find('.sk-comment-mentionrow').exists()).toBe(false)
	})

	it('shows neither row nor hint before the fetch answers', async () => {
		const w = makeWrapper()
		await startAdding(w)
		w.vm.members = []
		w.vm.membersLoaded = false
		await w.vm.$nextTick()
		expect(w.find('.sk-comment-mentionempty').exists()).toBe(false)
		expect(w.find('.sk-comment-mentionrow').exists()).toBe(false)
	})

	it('shows the picker, not the hint, when members exist', async () => {
		const w = makeWrapper()
		await startAdding(w)
		w.vm.members = [steve]
		w.vm.membersLoaded = true
		await w.vm.$nextTick()
		expect(w.find('.sk-comment-mentionrow').exists()).toBe(true)
		expect(w.find('.sk-comment-mentionempty').exists()).toBe(false)
	})
})
