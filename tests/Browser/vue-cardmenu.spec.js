// Card ⋯ menu (Alain, 2026-07-19): Détails opens the card; Modifier le titre
// renames it inline on the tile. (Couleur / m'affecter / déplacer come later.)
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-cardmenu'
const CARD = 'Carte à menu'
const RENAMED = 'Carte renommée inline'

test.describe('menu par carte (Vue)', () => {
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

	async function makeCard(page) {
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
		await expect(page.locator('.sk-vue-card', { hasText: CARD })).toHaveCount(1)
	}

	async function openCardMenu(page, card) {
		await card.hover()
		await card.getByRole('button', { name: 'Menu de la carte' }).click()
		await expect(page.locator('.sk-col-menu')).toBeVisible()
	}

	test('modifier le titre inline depuis le menu ⋯', async ({ page }) => {
		await makeCard(page)
		const card = page.locator('.sk-vue-card', { hasText: CARD })

		await openCardMenu(page, card)
		await page.locator('.sk-col-menu').getByRole('button', { name: 'Modifier le titre' }).click()

		const input = page.locator('.sk-vue-card-rename')
		await expect(input).toBeVisible()
		await input.fill(RENAMED)
		await input.press('Enter')

		await expect(page.locator('.sk-vue-card', { hasText: RENAMED })).toHaveCount(1)
		await expect(page.locator('.sk-vue-card', { hasText: CARD })).toHaveCount(0)
	})

	test('Détails ouvre la carte', async ({ page }) => {
		await makeCard(page)
		const card = page.locator('.sk-vue-card', { hasText: CARD })

		await openCardMenu(page, card)
		await page.locator('.sk-col-menu').getByRole('button', { name: 'Détails de la carte' }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
	})
})
