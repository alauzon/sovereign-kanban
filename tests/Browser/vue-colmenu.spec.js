// Per-column header (Alain, 2026-07-19): click the list NAME to rename it (no
// pencil), and a ⋯ menu for the list actions (mark all done / archive / delete).
// The menu is teleported to <body> so the columns' overflow can't clip it.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-colmenu'
const CARD = 'Carte à terminer'
const NEWNAME = 'zzz-liste-renommee'

test.describe('en-tête de colonne (Vue)', () => {
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
		await page.goto('/apps/sovereign-kanban/?vue=0')
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

	function openColMenu(page) {
		return page.locator('.sk-vue-column-head').first().getByRole('button', { name: 'Menu de la liste' }).click()
	}

	test('cliquer le nom pour renommer, puis le menu ⋯ pour supprimer', async ({ page }) => {
		await openBoard(page)

		// Rename: click the name itself → the inline input appears.
		await page.locator('.sk-vue-column-name').first().click()
		const input = page.locator('.sk-vue-column-rename')
		await expect(input).toBeVisible()
		await input.fill(NEWNAME)
		await input.press('Enter')
		await expect(page.locator('.sk-vue-column-name', { hasText: NEWNAME })).toHaveCount(1)

		// Delete via the ⋯ menu.
		const count = await page.locator('.sk-vue-column').count()
		await openColMenu(page)
		await page.locator('.sk-col-menu').getByRole('button', { name: 'Supprimer la liste' }).click()
		await expect(page.locator('.sk-vue-column-name', { hasText: NEWNAME })).toHaveCount(0)
		await expect(page.locator('.sk-vue-column')).toHaveCount(count - 1)
	})

	test('renommer une liste puis cliquer ailleurs (blur) enregistre le nom', async ({ page }) => {
		await openBoard(page)

		// Edit the name, then blur (click elsewhere) WITHOUT pressing Enter.
		await page.locator('.sk-vue-column-name').first().click()
		const input = page.locator('.sk-vue-column-rename')
		await expect(input).toBeVisible()
		await input.fill('zzz-blur-save')
		await input.blur()

		// Alain, 2026-07-19: blur should SAVE, not discard.
		await expect(page.locator('.sk-vue-column-name', { hasText: 'zzz-blur-save' })).toHaveCount(1)
	})

	test('le menu ⋯ termine toutes les cartes de la liste', async ({ page }) => {
		await openBoard(page)

		// A card in the first list, open (not done). Wait for the tile to render
		// (loadCards done → cardsByColumn populated) BEFORE opening the menu, else
		// mark-all-done acts on an empty column.
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill(CARD)
		await page.getByPlaceholder('Titre de la carte').press('Enter')
		await expect(page.locator('.sk-vue-card', { hasText: CARD })).toHaveCount(1)
		await expect(page.locator('.sk-vue-card--done', { hasText: CARD })).toHaveCount(0)

		// Mark the whole list done via the ⋯ menu.
		await openColMenu(page)
		await page.locator('.sk-col-menu').getByRole('button', { name: 'Définir les cartes comme « terminées »' }).click()
		await expect(page.locator('.sk-vue-card--done', { hasText: CARD })).toHaveCount(1)
	})
})
