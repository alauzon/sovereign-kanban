import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

// The @mention picker (Alain, 2026-07-22, option B): the NC rich editor won't feed
// custom @ suggestions in our mode, so a member picker inserts the mention instead.
// Picking a member must insert the canonical @[label](mention://user/uid) the server
// parses — into the rich editor node when mounted, else appended to the draft.
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
	it('appends the canonical mention markdown to the draft (textarea fallback)', () => {
		const w = makeWrapper()
		w.vm.editorMounted = false
		w.vm.draft = 'merci'
		w.vm.mention(steve)
		expect(w.vm.draft).toBe('merci @[Steve Lauzier](mention://user/StevLauz) ')
		expect(w.vm.mentionValue).toBe(null)
	})

	it('inserts a mention NODE into the rich editor, leaving the draft to onUpdate', () => {
		const w = makeWrapper()
		const insertAtCursor = vi.fn()
		w.vm.editorMounted = true
		w.vm.editorInstance = { insertAtCursor }
		w.vm.draft = ''
		w.vm.mention(steve)
		expect(insertAtCursor).toHaveBeenCalledTimes(1)
		const arg = insertAtCursor.mock.calls[0][0]
		expect(arg[0]).toEqual({ type: 'mention', attrs: { id: 'StevLauz', label: 'Steve Lauzier' } })
		// The rich path does not touch the draft — the editor's onUpdate owns it.
		expect(w.vm.draft).toBe('')
	})

	it('falls back to the draft when the editor insert throws', () => {
		const w = makeWrapper()
		w.vm.editorMounted = true
		w.vm.editorInstance = { insertAtCursor: () => { throw new Error('nope') } }
		w.vm.draft = ''
		w.vm.mention(steve)
		expect(w.vm.draft).toBe('@[Steve Lauzier](mention://user/StevLauz) ')
	})

	it('a null pick is a no-op that just resets the picker', () => {
		const w = makeWrapper()
		w.vm.draft = 'x'
		w.vm.mention(null)
		expect(w.vm.draft).toBe('x')
		expect(w.vm.mentionValue).toBe(null)
	})
})
