// "Mark as done" status (Alain, 2026-07-19: completed_at). The card modal has a
// "Marquer comme fait" button; a done card shows on its tile (✓ + strike) and the
// status filter keeps only done / open cards.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-done-ui'
const CARD = 'Carte terminable'

test.describe('statut « fait » (Vue)', () => {
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

	test('marquer une carte comme faite l\'affiche terminée et la filtre', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('button', { name: 'Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()

		// Card, then open it and mark done.
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill(CARD)
		await page.getByPlaceholder('Titre de la carte').press('Enter')
		await page.locator('.sk-vue-card', { hasText: CARD }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
		await page.locator('.sk-detail-vue').getByRole('button', { name: 'Marquer comme fait' }).click()
		await expect(page.getByRole('button', { name: '✓ Fait' })).toBeVisible()
		await page.getByRole('button', { name: 'Enregistrer' }).click()
		await expect(page.locator('.sk-detail-vue')).toHaveCount(0)

		// Tile shows it done.
		await expect(page.locator('.sk-vue-card--done', { hasText: CARD })).toHaveCount(1)

		// Status filter "À faire" hides it; "Terminée" shows it.
		await page.getByRole('button', { name: 'Filtres' }).click()
		const statusGroup = page.locator('.sk-filter-group', { hasText: 'Statut' })
		await statusGroup.getByRole('button', { name: 'À faire' }).click()
		await expect(page.locator('.sk-vue-card', { hasText: CARD })).toHaveCount(0)
		await statusGroup.getByRole('button', { name: 'À faire' }).click()
		await statusGroup.getByRole('button', { name: 'Terminée' }).click()
		await expect(page.locator('.sk-vue-card', { hasText: CARD })).toHaveCount(1)
	})
})
