// Horizontal scrollbar reachability (Alain, 2026-07-19: it only appeared after
// scrolling all the way down). The columns area must fit the viewport height so
// its bottom (and the horizontal scrollbar) stays on screen; a tall column
// scrolls its own cards instead of stretching the whole area below the fold.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-scroll'

test.describe('scroll du tableau (Vue)', () => {
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

	test('la zone des colonnes tient dans le viewport ; les cartes scrollent en interne', async ({ page }) => {
		await page.setViewportSize({ width: 1100, height: 800 })
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('button', { name: 'Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()

		// Fill the first column with enough cards to overflow a short viewport.
		for (let i = 0; i < 10; i++) {
			await page.getByRole('button', { name: '+ Carte' }).first().click()
			await page.getByPlaceholder('Titre de la carte').fill('Carte ' + i)
			await page.getByPlaceholder('Titre de la carte').press('Enter')
		}
		await expect(page.locator('.sk-vue-column-cards').first().locator('.sk-vue-card')).toHaveCount(10)

		// Now shrink the viewport: the column can't show all 10 cards at once.
		await page.setViewportSize({ width: 1100, height: 340 })

		// The columns area does not extend below the viewport bottom.
		const info = await page.locator('.sk-vue-columns').evaluate((el) => ({
			bottom: Math.round(el.getBoundingClientRect().bottom),
			vh: window.innerHeight,
		}))
		expect(info.bottom).toBeLessThanOrEqual(info.vh + 2)

		// The first column's card area scrolls its own cards (content taller than
		// the visible area).
		const scrolls = await page.locator('.sk-vue-column-cards').first().evaluate((el) => el.scrollHeight > el.clientHeight + 1)
		expect(scrolls).toBe(true)
	})
})
