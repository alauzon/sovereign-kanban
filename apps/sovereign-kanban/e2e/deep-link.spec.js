import { test, expect } from '@playwright/test'

// The card deep-link is meant to be SHAREABLE (Alain, 2026-07-22): opening a card
// reflects #<board>/<card> in the URL, and loading that URL cold must reopen the
// same card — that's what makes pasting the link to a board member work, and what
// the @mention notification relies on to land on the right card.
//
// Self-contained and read-only: it discovers a real card id from the running
// board, then cold-boots that exact URL with a full reload.
//
// A cold boot on a real instance (bundle download + NC framework boot + board
// load + card fetch) legitimately takes several seconds — on the encrypted ET
// instance with a large board it lands near 5s — so the reopen assertion gets a
// generous timeout. The point is that it opens AND STAYS open, not that it is fast.
test('a card deep-link URL reopens the card on a cold load (shareable)', async ({ page }) => {
	test.setTimeout(60_000)
	const board = process.env.NC_TEST_BOARD || 'bienvenue'
	await page.goto('/apps/sovereign-kanban/#' + board)

	// Open the first card; the app reflects it as #<board>/<card> in the URL.
	await page.locator('.sk-vue-card').first().click()
	const detail = page.locator('.sk-detail-vue')
	await expect(detail).toBeVisible()
	await expect
		.poll(() => new URL(page.url()).hash)
		.toMatch(new RegExp('^#' + board + '/[^/]+'))

	const deepLink = page.url()
	const title = await detail.locator('.sk-detail-title-input').inputValue()
	expect(title.length).toBeGreaterThan(0)

	// Cold boot at the deep link: a full reload re-runs the SPA from scratch at this
	// URL, exactly as a visitor pasting the shared link would experience it.
	await page.reload()

	// The same card reopens by itself, on the same board — and STAYS open (no stray
	// hashchange closing it). Generous timeout for the real cold-boot latency.
	await expect(detail).toBeVisible({ timeout: 20_000 })
	await expect(detail.locator('.sk-detail-title-input')).toHaveValue(title)
	// Still open a moment later — proves it didn't open-then-close.
	await page.waitForTimeout(1000)
	await expect(detail).toBeVisible()
	expect(new URL(page.url()).hash).toMatch(new RegExp('^#' + board + '/'))
})
