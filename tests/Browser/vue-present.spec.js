// Presentation menu (Alain, 2026-07-19): a compact display toggle tightens the
// tiles. (Cover images need an image attachment — tested manually.)
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-present'
const CARD = 'Carte compacte'

test.describe('menu présentation (Vue)', () => {
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

	test('la bascule « Affichage compact » resserre les tuiles', async ({ page }) => {
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

		// Not compact yet.
		await expect(page.locator('.sk-vue-columns--compact')).toHaveCount(0)

		// Open the Présentation menu and toggle compact.
		await page.getByRole('button', { name: 'Présentation' }).click()
		await page.getByText('Affichage compact').click()
		await expect(page.locator('.sk-vue-columns--compact')).toHaveCount(1)

		// « Afficher l'identifiant » shows the card id on the tile.
		await expect(page.locator('.sk-vue-card-id')).toHaveCount(0)
		await page.getByText('Afficher l\'identifiant').click()
		await expect(page.locator('.sk-vue-card-id')).toHaveCount(1)

		// The prefs persist across a reload (localStorage) — checked on compact,
		// same mechanism as showId.
		await page.reload()
		await dismissWizard(page)
		await expect(page.locator('.sk-vue-columns--compact')).toHaveCount(1)
	})
})
