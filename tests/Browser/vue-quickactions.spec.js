// Card actions from the tile ⋯ menu (Alain, 2026-07-19: mark done / reopen and
// delete live INSIDE the card menu now, not as separate hover buttons).
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-quick'
const CARD = 'Carte rapide'

test.describe('actions de carte via le menu ⋯ (Vue)', () => {
	test.beforeEach(async ({ page }) => {
		page.on('dialog', (d) => d.accept())
	})

	test.afterEach(async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=0')
		await dismissWizard(page)
		if (await page.locator('.sk-board-tab', { hasText: BOARD }).count()) {
			await page.locator('.sk-board-tab', { hasText: BOARD }).first().click()
			await page.getByRole('button', { name: 'Éditer' }).click()
			await page.getByRole('button', { name: 'Supprimer le tableau' }).click()
			await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(0)
		}
	})

	async function openMenu(page, card) {
		await card.hover()
		await card.getByRole('button', { name: 'Menu de la carte' }).click()
		await expect(page.locator('.sk-col-menu')).toBeVisible()
		return page.locator('.sk-col-menu')
	}

	test('marquer fait, rouvrir puis supprimer depuis le menu', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('button', { name: 'Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill(CARD)
		await page.getByPlaceholder('Titre de la carte').press('Enter')

		const card = page.locator('.sk-vue-card', { hasText: CARD })
		await expect(card).toHaveCount(1)

		// Mark done from the menu → the card becomes --done.
		let menu = await openMenu(page, card)
		await menu.getByRole('button', { name: 'Marquer comme fait' }).click()
		await expect(page.locator('.sk-vue-card--done', { hasText: CARD })).toHaveCount(1)

		// Reopen from the menu → no longer done.
		menu = await openMenu(page, card)
		await menu.getByRole('button', { name: 'Rouvrir' }).click()
		await expect(page.locator('.sk-vue-card--done', { hasText: CARD })).toHaveCount(0)

		// Delete from the menu → the card disappears.
		menu = await openMenu(page, card)
		await menu.getByRole('button', { name: 'Supprimer la carte' }).click()
		await expect(page.locator('.sk-vue-card', { hasText: CARD })).toHaveCount(0)
	})
})
