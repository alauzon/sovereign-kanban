import { describe, it, expect } from 'vitest'

// Proves the Vitest + jsdom harness itself runs before we lean on it.
describe('harness', () => {
	it('runs and has a DOM', () => {
		expect(typeof document).toBe('object')
		expect(1 + 1).toBe(2)
	})
})
