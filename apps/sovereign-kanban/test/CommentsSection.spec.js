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
