// Browser test: the phase-2 Vue shell mounts on ?vue=1 and lists the boards.
//
// The shell reads the SAME REST API as the vanilla app, so the test creates a
// board through the vanilla UI, then loads ?vue=1 and checks the board appears
// in the Nextcloud-native navigation (NcAppNavigationItem). This proves the shell
// is wired to real data — not that the migration is done (card content is still
// vanilla-only). The default (no ?vue) must stay the vanilla app, untouched.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-shell'

test.describe('shell Vue phase 2 — la navigation liste les tableaux', () => {
	test.beforeEach(async ({ page }) => {
		page.on('dialog', (d) => d.accept())
		await page.goto('/apps/sovereign-kanban/?vue=0')
		await dismissWizard(page)
		if (await page.locator('.sk-board-tab', { hasText: BOARD }).count()) {
			await deleteBoard(page)
		}
	})

	test.afterEach(async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=0')
		await dismissWizard(page)
		if (await page.locator('.sk-board-tab', { hasText: BOARD }).count()) {
			await deleteBoard(page)
		}
	})

	async function deleteBoard(page) {
		await page.locator('.sk-board-tab', { hasText: BOARD }).first().click()
		await page.getByRole('button', { name: 'Éditer' }).click()
		await page.getByRole('button', { name: 'Supprimer le tableau' }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(0)
	}

	test('le tableau créé en vanilla apparaît dans le shell Vue', async ({ page }) => {
		// Create a board with the vanilla app.
		await page.getByRole('button', { name: '+ Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(1)

		// Now load the Vue shell and check the board is listed in the NC navigation.
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await expect(page.locator('#sk-vue')).toBeAttached()
		// The board name appears as a navigation entry (NcAppNavigationItem).
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()
	})

	test('sans ?vue, le vanilla reste intact', async ({ page }) => {
		// The home-made header is a vanilla-only marker; it must still be there.
		await expect(page.locator('#sk-app')).toBeAttached()
		await expect(page.locator('#sk-vue')).toHaveCount(0)
	})
})
