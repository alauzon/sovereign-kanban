import { test, expect } from '@playwright/test'

// Carte 78fc32 / the case Alain caught twice in a screenshot: typing « @st » in a
// card comment shows « No suggestion found » / « Aucune suggestion » instead of
// suggesting the board member StevLauz.
//
// This is the RED test for that gap: it fails today (no suggestion is fed to the
// Text editor) and turns green once SK feeds the editor its board members.
//
// Runs only against a real instance with a test credential in e2e/.env
// (NC_TEST_URL/USER/PASSWORD). Selectors are a best first guess — adjust on the
// first real run.
test('typing @st in a card comment suggests StevLauz, not « Aucune suggestion »', async ({ page }) => {
	const board = process.env.NC_TEST_BOARD || 'bienvenue'
	await page.goto('/apps/sovereign-kanban/#' + board)

	// Open the first card, then its Commentaires tab.
	await page.locator('.sk-vue-card').first().click()
	await page.getByRole('button', { name: /Commentaires/i }).click()

	// Focus the comment field (Text editor contenteditable, or textarea fallback)
	// and start a mention.
	const field = page.locator('[contenteditable="true"], textarea').last()
	await field.click()
	await field.type('@st')

	// The exact failure Alain saw must NOT be on screen…
	await expect(page.getByText(/No suggestion found|Aucune suggestion/i)).toHaveCount(0)
	// …and a member suggestion (StevLauz / Steve) MUST be offered.
	await expect(page.getByText(/StevLauz|Steve/i).first()).toBeVisible()
})
