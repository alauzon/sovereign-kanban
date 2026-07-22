import { test, expect } from '@playwright/test'

// Carte 78fc32 / the case Alain caught in the screenshot: typing @ in a card
// comment must suggest a board MEMBER — today it shows « No suggestion found ».
// This spec is RED until SK feeds the Text editor its members; it is the e2e
// characterization of that gap. Selectors are a best first guess — adjust on the
// first real run (I can't run it without the test credential).
test('typing @ in a card comment suggests a board member, not « No suggestion found »', async ({ page }) => {
	const board = process.env.NC_TEST_BOARD || 'bienvenue'
	await page.goto('/apps/sovereign-kanban/#' + board)

	// Open the first card on the board.
	await page.locator('.sk-vue-card').first().click()

	// Go to the Commentaires tab of the card.
	await page.getByRole('button', { name: /Commentaires/i }).click()

	// Focus the comment field (Text editor contenteditable, or textarea fallback).
	const field = page.locator('[contenteditable="true"], textarea').last()
	await field.click()
	await field.type('@')

	// The mention menu must offer at least one member — never « No suggestion found ».
	await expect(page.getByText(/No suggestion found|Aucune suggestion/i)).toHaveCount(0)
	await expect(page.locator('.tribute-container li, .mention-list li, [role="listbox"] [role="option"]').first())
		.toBeVisible()
})
