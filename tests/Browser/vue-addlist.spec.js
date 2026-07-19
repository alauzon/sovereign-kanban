// Add a list/column inline from the board (Alain, 2026-07-18): a "+ Liste" button
// after the last column becomes an input; a name creates a new column.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-addlist'
const LIST = 'zzz-e2e-nouvelle-liste'

test.describe('ajout de liste inline (Vue)', () => {
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

	test('« + Liste » crée une nouvelle colonne', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/')
		await dismissWizard(page)
		await page.getByRole('button', { name: '+ Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(1)

		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()

		const before = await page.locator('.sk-vue-column-name').count()

		await page.getByRole('button', { name: '+ Liste' }).click()
		await page.getByPlaceholder('Nom de la liste').fill(LIST)
		await page.getByPlaceholder('Nom de la liste').press('Enter')

		await expect(page.locator('.sk-vue-column-name', { hasText: LIST })).toHaveCount(1)
		expect(await page.locator('.sk-vue-column-name').count()).toBe(before + 1)
	})
})
