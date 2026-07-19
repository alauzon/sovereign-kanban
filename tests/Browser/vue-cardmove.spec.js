// Card drag between columns still works after onDrop learned to tell a column
// drag from a card drag (D4 of the Deck→SK gestures). Uses native HTML5 DnD with
// a shared DataTransfer.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-cardmove'
const CARD = 'Carte a deplacer'

test.describe('déplacer une carte entre colonnes (Vue)', () => {
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

	test('glisser une carte la déplace dans une autre colonne', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('button', { name: 'Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()

		// Card in the first column.
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill(CARD)
		await page.getByPlaceholder('Titre de la carte').press('Enter')
		await expect(page.locator('.sk-vue-column').first().locator('.sk-vue-card', { hasText: CARD })).toHaveCount(1)

		// Drag it onto the second column.
		await page.evaluate((title) => {
			const card = [...document.querySelectorAll('.sk-vue-card')].find((c) => c.textContent.includes(title))
			const col2 = document.querySelectorAll('.sk-vue-column')[1]
			const dt = new DataTransfer()
			const opts = (d) => ({ dataTransfer: d, bubbles: true, cancelable: true })
			card.dispatchEvent(new DragEvent('dragstart', opts(dt)))
			col2.dispatchEvent(new DragEvent('dragover', opts(dt)))
			col2.dispatchEvent(new DragEvent('drop', opts(dt)))
		}, CARD)

		// It now lives in the second column, not the first.
		await expect.poll(async () =>
			page.locator('.sk-vue-column').nth(1).locator('.sk-vue-card', { hasText: CARD }).count(),
		).toBe(1)
		await expect(page.locator('.sk-vue-column').first().locator('.sk-vue-card', { hasText: CARD })).toHaveCount(0)
	})
})
