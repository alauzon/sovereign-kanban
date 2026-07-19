// Reorder columns by drag-and-drop in the board editor (Alain, 2026-07-18: drag
// instead of ↑/↓ arrows, and remove the arrows). Checks the arrows are gone, a
// drag handle is present, and dragging changes the order.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-colreorder'

test.describe('réordonner les colonnes par glisser (Vue)', () => {
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

	async function openEditor(page) {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('button', { name: 'Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()

		const entry = page.locator('.app-navigation-entry', { hasText: BOARD })
		await entry.hover()
		await entry.locator('button.action-item__menutoggle').click()
		await page.getByRole('menuitem', { name: 'Éditer' }).click()
		await expect(page.locator('.sk-col-row').first()).toBeVisible()
	}

	async function order(page) {
		return page.locator('.sk-col-row input[type="text"]').evaluateAll((els) => els.map((e) => e.value))
	}

	test('les flèches sont retirées, la poignée est là, glisser change l\'ordre', async ({ page }) => {
		await openEditor(page)

		// Arrows gone, handle present.
		await expect(page.locator('.sk-col-row').first().locator('.sk-col-handle')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Monter la colonne' })).toHaveCount(0)
		await expect(page.getByRole('button', { name: 'Descendre la colonne' })).toHaveCount(0)

		const before = await order(page)
		expect(before.length).toBeGreaterThanOrEqual(2)

		// Drag the first column onto the last → the first name is no longer first.
		const rows = page.locator('.sk-col-row')
		await rows.first().dragTo(rows.last())
		await expect.poll(async () => (await order(page))[0]).not.toBe(before[0])
	})
})
