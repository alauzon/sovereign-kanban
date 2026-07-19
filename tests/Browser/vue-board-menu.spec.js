// The per-board menu in the Vue shell (Alain, 2026-07-18: "il manque le menu par
// tableau"). Parity with the vanilla showBoardForm: create from the navigation,
// edit (rename) and delete from each item's action menu.
//
// Semantic locators (roles/text), so the test survives styling changes.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-boardmenu'
const RENAMED = 'zzz-e2e-boardmenu-r'

test.describe('menu par tableau (Vue)', () => {
	test.afterEach(async ({ page }) => {
		for (const name of [BOARD, RENAMED]) {
			await vanillaCleanup(page, name)
		}
	})

	// Independent teardown through the vanilla UI, so a half-failed test can't
	// leave a stray board behind.
	async function vanillaCleanup(page, name) {
		page.on('dialog', (d) => d.accept())
		await page.goto('/apps/sovereign-kanban/?vue=0')
		await dismissWizard(page)
		if (await page.locator('.sk-board-tab', { hasText: name }).count()) {
			await page.locator('.sk-board-tab', { hasText: name }).first().click()
			await page.getByRole('button', { name: 'Éditer' }).click()
			await page.getByRole('button', { name: 'Supprimer le tableau' }).click()
			await expect(page.locator('.sk-board-tab', { hasText: name })).toHaveCount(0)
		}
	}

	// Open the action (⋯) menu of a navigation entry and click one of its items.
	async function boardAction(page, boardName, actionName) {
		const entry = page.locator('.app-navigation-entry', { hasText: boardName })
		await entry.hover()
		await entry.locator('button.action-item__menutoggle').click()
		await page.getByRole('menuitem', { name: actionName }).click()
	}

	test('créer, renommer et supprimer un tableau depuis la navigation Vue', async ({ page }) => {
		page.on('dialog', (d) => d.accept())
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)

		// Create.
		await page.getByRole('button', { name: 'Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()

		// Edit → rename.
		await boardAction(page, BOARD, 'Éditer')
		const nameField = page.getByPlaceholder('Nom du tableau')
		await nameField.fill(RENAMED)
		await page.getByRole('button', { name: 'Enregistrer' }).click()
		await expect(page.getByRole('link', { name: RENAMED })).toBeVisible()
		await expect(page.getByRole('link', { name: BOARD, exact: true })).toHaveCount(0)

		// Delete.
		await boardAction(page, RENAMED, 'Supprimer')
		await expect(page.getByRole('link', { name: RENAMED })).toHaveCount(0)
	})
})
