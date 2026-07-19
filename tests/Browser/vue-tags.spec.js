// Label dropdown (NcSelect) in the Vue card modal (Alain, 2026-07-18, Deck
// screenshot: "Sélectionner ou créer une étiquette"). Create a tag via the
// selector and check it persists on save/reopen.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-tags'
const CARD = 'Carte tags'
const TAG = 'zzzurgent'

test.describe('sélecteur d\'étiquettes (Vue)', () => {
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

	async function openCard(page) {
		await page.goto('/apps/sovereign-kanban/?vue=0')
		await dismissWizard(page)
		await page.getByRole('button', { name: '+ Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(1)

		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill(CARD)
		await page.getByPlaceholder('Titre de la carte').press('Enter')
		await page.locator('.sk-vue-card', { hasText: CARD }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
	}

	test('créer une étiquette via le sélecteur et la persister', async ({ page }) => {
		await openCard(page)
		const field = page.locator('.sk-field', { hasText: 'Étiquettes' })
		const search = field.locator('input.vs__search')

		// Type a new tag and press Enter (taggable) → it becomes a selected chip.
		await search.click()
		await search.fill(TAG)
		await search.press('Enter')
		await expect(field.getByText(TAG, { exact: false })).toBeVisible()

		// Save, reopen: the tag stuck.
		await page.getByRole('button', { name: 'Enregistrer' }).click()
		await expect(page.locator('.sk-detail-vue')).toHaveCount(0)
		await page.locator('.sk-vue-card', { hasText: CARD }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
		await expect(page.locator('.sk-field', { hasText: 'Étiquettes' }).getByText(TAG, { exact: false })).toBeVisible()
	})
})
