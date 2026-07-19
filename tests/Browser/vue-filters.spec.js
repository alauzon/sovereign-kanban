// Board filter row (Alain, 2026-07-18): a funnel button opens a filter row;
// clicking a priority chip keeps only the matching cards; « Remise à 0 » clears
// it. Verifies the AND/OR logic through one dimension end to end.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-filters'
const CARD_A = 'Carte filtre A'
const CARD_B = 'Carte filtre B'

test.describe('filtres du tableau (Vue)', () => {
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

	async function addCard(page, title) {
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill(title)
		await page.getByPlaceholder('Titre de la carte').press('Enter')
		await expect(page.locator('.sk-vue-card', { hasText: title })).toHaveCount(1)
	}

	test('filtrer par priorité ne montre que les cartes correspondantes', async ({ page }) => {
		// Board + two cards (both default priority 3).
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
		await addCard(page, CARD_A)
		await addCard(page, CARD_B)

		// Bump card B to priority 5.
		await page.locator('.sk-vue-card', { hasText: CARD_B }).click()
		await page.locator('.sk-field', { hasText: 'Priorité' }).locator('select').selectOption('5')
		await page.getByRole('button', { name: 'Enregistrer' }).click()
		await expect(page.locator('.sk-detail-vue')).toHaveCount(0)

		// Open filters, keep only priority 5 → A hidden, B shown.
		await page.getByRole('button', { name: 'Filtres' }).click()
		await expect(page.locator('.sk-filterbar')).toBeVisible()
		await page.locator('.sk-filter-group', { hasText: 'Priorité' }).getByRole('button', { name: '5', exact: false }).click()
		await expect(page.locator('.sk-vue-card', { hasText: CARD_A })).toHaveCount(0)
		await expect(page.locator('.sk-vue-card', { hasText: CARD_B })).toHaveCount(1)

		// Reset → both back.
		await page.getByRole('button', { name: 'Remise à 0' }).click()
		await expect(page.locator('.sk-vue-card', { hasText: CARD_A })).toHaveCount(1)
		await expect(page.locator('.sk-vue-card', { hasText: CARD_B })).toHaveCount(1)
	})
})
