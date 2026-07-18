// Parity test for the Vue content migration (?vue=1).
//
// The characterization test (characterization.spec.js) pins the vanilla
// behaviour of D2 (create) and D7 (rename). This test proves the Vue shell
// reproduces that SAME observable behaviour — a card created shows up, a rename
// changes the visible title. Same gestures, same result, different engine.
//
// The board is created with the vanilla app (board creation is not in the Vue
// shell yet); the shell reads the same API, so it lists it.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-vuecontent'

test.describe('migration du contenu en Vue — parité des gestes carte', () => {
	test.beforeEach(async ({ page }) => {
		page.on('dialog', (d) => d.accept())
		await page.goto('/apps/sovereign-kanban/')
		await dismissWizard(page)
		await cleanup(page)
		// Create the board in vanilla.
		await page.getByRole('button', { name: '+ Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(1)
	})

	test.afterEach(async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/')
		await dismissWizard(page)
		await cleanup(page)
	})

	async function cleanup(page) {
		if (await page.locator('.sk-board-tab', { hasText: BOARD }).count()) {
			await page.locator('.sk-board-tab', { hasText: BOARD }).first().click()
			await page.getByRole('button', { name: 'Éditer' }).click()
			await page.getByRole('button', { name: 'Supprimer le tableau' }).click()
			await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(0)
		}
	}

	async function openBoardInVue(page) {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		// Select the board in the Vue navigation.
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()
	}

	// D2 in Vue — creating a card makes it appear by its title.
	test('D2 (Vue) — créer une carte la fait apparaître', async ({ page }) => {
		await openBoardInVue(page)

		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill('Tâche en Vue')
		await page.getByPlaceholder('Titre de la carte').press('Enter')

		await expect(page.getByText('Tâche en Vue', { exact: true })).toBeVisible()
	})

	// D7 in Vue — renaming a card changes the visible title.
	test('D7 (Vue) — renommer une carte change le titre visible', async ({ page }) => {
		await openBoardInVue(page)
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill('Avant Vue')
		await page.getByPlaceholder('Titre de la carte').press('Enter')
		await expect(page.getByText('Avant Vue', { exact: true })).toBeVisible()

		// Open the card (modal), rename, save.
		await page.getByText('Avant Vue', { exact: true }).click()
		const titleField = page.locator('.sk-detail-title-input')
		await expect(titleField).toHaveValue('Avant Vue')
		await titleField.fill('Après Vue')
		await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()

		await expect(page.getByText('Après Vue', { exact: true })).toBeVisible()
		await expect(page.getByText('Avant Vue', { exact: true })).toHaveCount(0)
	})
})
