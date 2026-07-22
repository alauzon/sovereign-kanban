import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

// Network and router are mocked; the @nextcloud/vue components are stubbed via
// the alias in vitest.config.js. We assert OUR wiring, not their rendering.
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: { shares: [] } })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import axios from '@nextcloud/axios'
import SharePanel from '../src/components/SharePanel.vue'

describe('SharePanel', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: { shares: [] } })
	})

	it('onPick shares with the id, not the typed text (Steve, e85179)', async () => {
		const w = mount(SharePanel, { props: { boardId: 'b1' } })
		await w.vm.$nextTick()
		w.vm.onPick({ type: 'user', id: 'bob', label: 'Bob Martin', email: 'bob@x.org' })
		expect(w.vm.shareWith).toBe('bob')
		expect(w.vm.selectedSharee.label).toBe('Bob Martin')
	})

	it('changing the recipient type clears the picked value', async () => {
		const w = mount(SharePanel, { props: { boardId: 'b1' } })
		await w.vm.$nextTick()
		w.vm.onPick({ type: 'user', id: 'bob', label: 'Bob' })
		w.vm.type = 'group'
		w.vm.onTypeChange()
		expect(w.vm.shareWith).toBe('')
		expect(w.vm.selectedSharee).toBe(null)
	})

	it('onSearch enriches results so the avatar/name render (user rows carry user+displayName)', async () => {
		vi.useFakeTimers()
		const w = mount(SharePanel, { props: { boardId: 'b1' } })
		await w.vm.$nextTick()
		axios.get.mockResolvedValue({ data: { sharees: [{ type: 'user', id: 'bob', label: 'Bob', email: 'bob@x.org' }] } })
		w.vm.type = 'user'
		w.vm.onSearch('bo')
		await vi.advanceTimersByTimeAsync(300)
		expect(w.vm.sharees.length).toBe(1)
		expect(w.vm.sharees[0].displayName).toBe('Bob')
		expect(w.vm.sharees[0].user).toBe('bob')
		vi.useRealTimers()
	})
})
