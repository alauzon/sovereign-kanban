// Board sharing panel in the Vue board editor (Alain, 2026-07-18). A full
// add/revoke round-trip needs a third test account; this smoke test proves the
// panel mounts for the owner and loads the (empty) share list without error —
// i.e. GET /shares wired correctly and the migrated UI renders.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-share'

test.describe('panneau de partage (Vue)', () => {
	test.beforeEach(async ({ page }) => {
		// One dialog handler for the whole test lifecycle (registering it twice
		// makes the second accept() throw "already handled").
		page.on('dialog', (d) => d.accept())
	})

	test.afterEach(async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/')
		await dismissWizard(page)
		if (await page.locator('.sk-board-tab', { hasText: BOARD }).count()) {
			await page.locator('.sk-board-tab', { hasText: BOARD }).first().click()
			await page.getByRole('button', { name: 'Éditer' }).click()
			await page.getByRole('button', { name: 'Supprimer le tableau' }).click()
			await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(0)
		}
	})

	test('le panneau de partage s\'affiche pour le propriétaire et charge la liste', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)

		await page.getByRole('button', { name: 'Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()

		// Open the editor via the item action menu.
		const entry = page.locator('.app-navigation-entry', { hasText: BOARD })
		await entry.hover()
		await entry.locator('button.action-item__menutoggle').click()
		await page.getByRole('menuitem', { name: 'Éditer' }).click()

		// The share section is present, and the list resolved (not stuck loading).
		await expect(page.locator('.sk-share-panel')).toBeVisible()
		await expect(page.getByText('Pas encore partagé.')).toBeVisible()
		// The add form is there: a recipient field and a Partager button.
		await expect(page.getByPlaceholder('Usager ou groupe…')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Partager' })).toBeVisible()
	})
})
