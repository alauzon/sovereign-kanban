// Sovereign activity journal (Alain, 2026-07-19, option C) + author in the summary.
// Creating and editing a card feeds an append-only journal shown in the Activité
// tab; the card summary shows who created it.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-activity-ui'
const CARD = 'Carte tracée'

test.describe('activité + auteur (Vue)', () => {
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

	test('le journal enregistre création + édition et le résumé montre l\'auteur', async ({ page }) => {
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
		await page.locator('.sk-vue-card', { hasText: CARD }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()

		// Summary shows the author (display name of the test account).
		await expect(page.locator('.sk-detail-summary')).toContainText('Test 1')

		// Make an edit that journals an "updated" event: change the priority.
		await page.locator('.sk-detail-vue').locator('select').nth(1).selectOption('1')
		await page.getByRole('button', { name: 'Enregistrer' }).click()
		await expect(page.locator('.sk-detail-vue')).toHaveCount(0)

		// Reopen and read the Activité tab.
		await page.locator('.sk-vue-card', { hasText: CARD }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
		await page.locator('.sk-detail-vue').getByRole('button', { name: 'Activité' }).click()

		const list = page.locator('.sk-activity-list')
		await expect(list).toBeVisible()
		// Newest first: the edit is on top, the creation at the bottom.
		await expect(list.locator('.sk-activity-item').first()).toContainText('a modifié')
		await expect(list.locator('.sk-activity-item').last()).toContainText('a créé la carte')
		// The actor label is shown.
		await expect(list.locator('.sk-activity-item').last()).toContainText('Test 1')
	})
})
