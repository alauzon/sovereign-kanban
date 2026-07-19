// Card modal tabs (Alain, 2026-07-19: Deck's Détails / Commentaires / … tabs).
// Details is active by default (fields visible); switching to Comments shows the
// comment box and hides the detail fields.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-tabs'
const CARD = 'Carte onglets'

test.describe('onglets de la carte (Vue)', () => {
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

	test('Détails actif par défaut, bascule vers Commentaires', async ({ page }) => {
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

		const tabs = page.locator('.sk-detail-tabs')
		// Details is the default: a detail field (Assignés) is visible, comments not.
		await expect(page.locator('.sk-field', { hasText: 'Assignés' })).toBeVisible()
		await expect(page.getByText('Ajouter un commentaire…')).toBeHidden()

		// Switch to Comments: the comment box shows, detail fields hide.
		await tabs.getByRole('button', { name: 'Commentaires' }).click()
		await expect(page.getByText('Ajouter un commentaire…')).toBeVisible()
		await expect(page.locator('.sk-field', { hasText: 'Assignés' })).toBeHidden()

		// Back to Details.
		await tabs.getByRole('button', { name: 'Détails' }).click()
		await expect(page.locator('.sk-field', { hasText: 'Assignés' })).toBeVisible()
	})
})
