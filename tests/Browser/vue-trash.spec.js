// Corbeille (Alain, 2026-07-19, #6): deleting a card is soft (moves to trash);
// the Corbeille lists it, restores it to the board, or purges it for good.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-trash-ui'
const CARD = 'Carte à jeter'

test.describe('corbeille (Vue)', () => {
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

	test('supprimer → corbeille → restaurer, puis purger', async ({ page }) => {
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

		// Delete (soft) via the card ⋯ menu.
		await card.hover()
		await card.getByRole('button', { name: 'Menu de la carte' }).click()
		await page.locator('.sk-col-menu').getByRole('button', { name: 'Supprimer la carte' }).click()
		await expect(page.locator('.sk-vue-card', { hasText: CARD })).toHaveCount(0)

		// Corbeille lists it.
		await page.getByRole('button', { name: 'Corbeille' }).click()
		const item = page.locator('.sk-trash-item', { hasText: CARD })
		await expect(item).toHaveCount(1)

		// Restore → back on the board, trash empty.
		await item.getByRole('button', { name: 'Restaurer' }).click()
		await expect(page.getByText('La corbeille est vide.')).toBeVisible()
		await page.locator('.sk-help-overlay .sk-help-actions').getByRole('button', { name: 'Fermer' }).click()
		await expect(page.locator('.sk-vue-card', { hasText: CARD })).toHaveCount(1)

		// Delete again, then purge permanently.
		await card.hover()
		await card.getByRole('button', { name: 'Menu de la carte' }).click()
		await page.locator('.sk-col-menu').getByRole('button', { name: 'Supprimer la carte' }).click()
		await page.getByRole('button', { name: 'Corbeille' }).click()
		await page.locator('.sk-trash-item', { hasText: CARD }).getByRole('button', { name: 'Supprimer définitivement' }).click()
		await expect(page.getByText('La corbeille est vide.')).toBeVisible()
	})
})
