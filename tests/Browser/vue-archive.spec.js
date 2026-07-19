// Card archiving (Alain, 2026-07-19): archive from the ⋯ menu hides the card;
// the « Archivées » toggle reveals it (greyed); unarchive brings it back.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-archive-ui'
const CARD = 'Carte à ranger'

test.describe('archivage de carte (Vue)', () => {
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

	test('archiver cache la carte, la bascule la révèle, désarchiver la ramène', async ({ page }) => {
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

		// Archive → the card disappears from the board.
		let menu = await openMenu(page, card)
		await menu.getByRole('button', { name: 'Archiver la carte' }).click()
		await expect(page.locator('.sk-vue-card', { hasText: CARD })).toHaveCount(0)

		// Toggle « Archivées » → the card reappears, marked archived.
		await page.getByRole('button', { name: 'Afficher les archivées' }).click()
		await expect(page.locator('.sk-vue-card--archived', { hasText: CARD })).toHaveCount(1)

		// Unarchive from its menu → no longer archived.
		menu = await openMenu(page, card)
		await menu.getByRole('button', { name: 'Désarchiver la carte' }).click()
		await expect(page.locator('.sk-vue-card--archived', { hasText: CARD })).toHaveCount(0)
		await expect(page.locator('.sk-vue-card', { hasText: CARD })).toHaveCount(1)
	})
})
