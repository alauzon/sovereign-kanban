// Inline comment editing (Alain, 2026-07-19, #7): ✎ turns a comment into an
// editable textarea, save (PUT) updates it in place.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-commentedit'
const CARD = 'Carte à commenter'
const ORIG = 'Premier jet du commentaire'
const EDITED = 'Commentaire corrigé'

test.describe('édition inline des commentaires (Vue)', () => {
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

	test('éditer un commentaire sur place', async ({ page }) => {
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

		// Comments tab → add a comment (rich editor, or textarea fallback).
		await page.locator('.sk-detail-vue').getByRole('button', { name: 'Commentaires' }).click()
		await page.getByText('Ajouter un commentaire…').click()
		const rich = page.locator('.sk-comment-editor [contenteditable="true"]')
		const fallback = page.locator('.sk-comment-add .sk-comment-input')
		await rich.or(fallback).first().waitFor({ state: 'visible' })
		if (await rich.isVisible()) {
			await rich.click()
			await page.keyboard.type(ORIG)
		} else {
			await fallback.fill(ORIG)
		}
		await page.getByRole('button', { name: 'Commenter' }).click()
		await expect(page.locator('.sk-comment-body', { hasText: ORIG })).toHaveCount(1)

		// Edit it in place. Once editing, the body text moves into the textarea's
		// value, so the { hasText: ORIG } filter no longer matches — target the
		// edit textarea (only one, inside a .sk-comment) directly afterwards.
		await page.locator('.sk-comment', { hasText: ORIG }).getByRole('button', { name: 'Modifier ce commentaire' }).click()
		const box = page.locator('.sk-comment .sk-comment-input')
		await expect(box).toBeVisible()
		await expect(box).not.toHaveValue('')
		await box.fill(EDITED)
		// Scope « Enregistrer » to the comment editor (the card also has one).
		await page.locator('.sk-comment .sk-comment-editactions').getByRole('button', { name: 'Enregistrer' }).click()

		await expect(page.locator('.sk-comment-body', { hasText: EDITED })).toHaveCount(1)
		await expect(page.locator('.sk-comment-body', { hasText: ORIG })).toHaveCount(0)
	})
})
