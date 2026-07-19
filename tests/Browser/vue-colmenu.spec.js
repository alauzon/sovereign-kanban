// Per-column ⋯ menu (Alain, 2026-07-18): rename the list inline, delete the list.
// (Archive / mark done depend on the not-yet-built status, so they come later.)
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-colmenu'
const NEWNAME = 'zzz-liste-renommee'

test.describe('menu par colonne (Vue)', () => {
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

	async function openBoard(page) {
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
	}

	// Click a direct action button (✎ / ✕) in the first column's header.
	async function colAction(page, name) {
		await page.locator('.sk-vue-column-head').first().getByRole('button', { name }).click()
	}

	test('renommer une liste puis la supprimer', async ({ page }) => {
		await openBoard(page)
		const firstName = await page.locator('.sk-vue-column-name').first().textContent()

		// Rename the first list inline.
		await colAction(page, 'Renommer la liste')
		const input = page.locator('.sk-vue-column-rename')
		await expect(input).toBeVisible()
		await input.fill(NEWNAME)
		await input.press('Enter')
		await expect(page.locator('.sk-vue-column-name', { hasText: NEWNAME })).toHaveCount(1)

		// Delete it → its name disappears, one fewer column.
		const count = await page.locator('.sk-vue-column').count()
		await colAction(page, 'Supprimer la liste')
		await expect(page.locator('.sk-vue-column-name', { hasText: NEWNAME })).toHaveCount(0)
		await expect(page.locator('.sk-vue-column')).toHaveCount(count - 1)
		expect(firstName).not.toBe(NEWNAME)
	})
})
