// Keyboard shortcuts + help (Alain, 2026-07-19, #8): ? opens help, n starts a new
// card, f toggles filters. Ignored while typing in a field.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-shortcuts'

test.describe('raccourcis clavier (Vue)', () => {
	test.beforeEach(async ({ page }) => {
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

	test('? ouvre l\'aide, n crée une carte, f ouvre les filtres', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('button', { name: 'Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()
		// Move focus out of any field.
		await page.locator('.sk-vue-board-title').click()

		// ? → help overlay ; Échap ferme.
		await page.keyboard.press('Shift+Slash')
		await expect(page.locator('.sk-help-overlay')).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(page.locator('.sk-help-overlay')).toHaveCount(0)

		// f → filters row.
		await page.keyboard.press('f')
		await expect(page.locator('.sk-filter-group').first()).toBeVisible()
		await page.keyboard.press('f')

		// n → the new-card input on the first column.
		await page.locator('.sk-vue-board-title').click()
		await page.keyboard.press('n')
		await expect(page.getByPlaceholder('Titre de la carte')).toBeVisible()
	})
})
