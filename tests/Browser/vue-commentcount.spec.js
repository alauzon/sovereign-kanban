// Comment count badge on tiles (Alain, 2026-07-19, Deck's 💬 N). Add a comment,
// reload the board, the tile shows 💬 1.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-commentcount'
const CARD = 'Carte commentee'

test.describe('compte de commentaires sur la tuile (Vue)', () => {
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

	test('un commentaire fait apparaître 💬 1 sur la tuile', async ({ page }) => {
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

		// Open the card, go to Comments, add one.
		await page.locator('.sk-vue-card', { hasText: CARD }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
		await page.locator('.sk-detail-tabs').getByRole('button', { name: 'Commentaires' }).click()
		await page.getByText('Ajouter un commentaire…').click()
		const rich = page.locator('.sk-comment-editor [contenteditable="true"]')
		const fallback = page.locator('.sk-comment-input')
		await rich.or(fallback).first().waitFor({ state: 'visible' })
		if (await rich.isVisible()) {
			await rich.click()
			await page.keyboard.type('Un commentaire de test')
		} else {
			await fallback.fill('Un commentaire de test')
		}
		await page.getByRole('button', { name: 'Commenter' }).click()
		await expect(page.locator('.sk-comment-body', { hasText: 'Un commentaire de test' })).toBeVisible()

		// Reload the board; the tile carries 💬 1.
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-card', { hasText: CARD }).locator('.sk-vue-chip', { hasText: '💬 1' })).toHaveCount(1)
	})
})
