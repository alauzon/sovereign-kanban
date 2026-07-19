// Reorder columns by dragging the column headers on the board itself (Alain,
// 2026-07-19: drag the columns themselves, and the column list is removed from
// the board editor).
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-colreorder'

test.describe('réordonner les colonnes sur le tableau (Vue)', () => {
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

	async function openBoard(page) {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('button', { name: 'Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()
	}

	async function order(page) {
		return page.locator('.sk-vue-column-name').allTextContents()
	}

	test('glisser un en-tête de colonne réordonne les listes', async ({ page }) => {
		await openBoard(page)
		const before = await order(page)
		expect(before.length).toBeGreaterThanOrEqual(2)

		// Native HTML5 DnD with a shared DataTransfer (Playwright's dragTo drops the
		// custom data type between dragstart and drop). Drag the first header onto
		// the last column.
		await page.evaluate(() => {
			const heads = document.querySelectorAll('.sk-vue-column-head')
			const dt = new DataTransfer()
			const opts = (d) => ({ dataTransfer: d, bubbles: true, cancelable: true })
			heads[0].dispatchEvent(new DragEvent('dragstart', opts(dt)))
			heads[heads.length - 1].dispatchEvent(new DragEvent('dragover', opts(dt)))
			heads[heads.length - 1].dispatchEvent(new DragEvent('drop', opts(dt)))
		})
		await expect.poll(async () => (await order(page))[0]).not.toBe(before[0])
	})

	test('l\'éditeur de tableau ne gère plus les colonnes', async ({ page }) => {
		await openBoard(page)
		const entry = page.locator('.app-navigation-entry', { hasText: BOARD })
		await entry.hover()
		await entry.locator('button.action-item__menutoggle').click()
		await page.getByRole('menuitem', { name: 'Éditer' }).click()

		// The old column editor is gone: no "+ Colonne" button.
		await expect(page.getByRole('button', { name: '+ Colonne' })).toHaveCount(0)
		await expect(page.getByText('directement sur le tableau', { exact: false })).toBeVisible()
	})
})
