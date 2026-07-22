import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

// Concurrency guard on a card edit (carte 523b3b): save() must send the rev the
// card was opened at, and a 409 must refuse the edit visibly — not overwrite.
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import axios from '@nextcloud/axios'
import CardDetail from '../src/components/CardDetail.vue'

// Local child components are stubbed so only CardDetail's own logic runs.
const stubs = {
	CardShell: true, CommentsSection: true, DateField: true,
	RelationsField: true, AttachmentsSection: true,
}

function makeCard(over = {}) {
	return { id: 'c1', rev: 7, title: 'Titre', description: 'Corps', tags: [], assignees: [], relations: [], ...over }
}

// jsdom has no window.alert; the 409 path calls it.
beforeEach(() => {
	vi.clearAllMocks()
	window.alert = vi.fn()
})

describe('CardDetail concurrency guard', () => {
	it('save() sends the opened rev as baseRev', async () => {
		const w = mount(CardDetail, { props: { card: makeCard({ rev: 7 }), boardId: 'b1' }, global: { stubs } })
		await w.vm.save()
		expect(axios.put).toHaveBeenCalledTimes(1)
		const body = axios.put.mock.calls[0][1]
		expect(body.baseRev).toBe(7)
	})

	it('a 409 conflict refuses the edit: refresh + close, never saved', async () => {
		axios.put.mockRejectedValueOnce({ response: { status: 409, data: { error: 'conflict' } } })
		const w = mount(CardDetail, { props: { card: makeCard(), boardId: 'b1' }, global: { stubs } })
		await w.vm.save()
		expect(window.alert).toHaveBeenCalledTimes(1)
		expect(w.emitted('refresh')).toBeTruthy()
		expect(w.emitted('close')).toBeTruthy()
		expect(w.emitted('saved')).toBeFalsy()
		expect(w.vm.skipSave).toBe(true)
	})

	it('cannot delete a card from the editor — only the tile ⋯ menu (Steve, 3c5af1)', async () => {
		const w = mount(CardDetail, { props: { card: makeCard(), boardId: 'b1' }, global: { stubs } })
		await w.vm.$nextTick()
		// The delete path is gone from CardDetail: no remove() method, never emits deleted.
		expect(w.vm.remove).toBeUndefined()
		expect(w.emitted('deleted')).toBeFalsy()
	})

	it('a normal save emits saved', async () => {
		const w = mount(CardDetail, { props: { card: makeCard(), boardId: 'b1' }, global: { stubs } })
		await w.vm.save()
		expect(w.emitted('saved')).toBeTruthy()
		expect(w.emitted('close')).toBeFalsy()
	})
}) 
