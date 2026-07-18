// Card comments in the Vue modal (migration of the vanilla comments section).
// Add a comment through the rich editor (or textarea fallback), see it appear,
// delete it. Backed by POST/GET/DELETE .../comments.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-comments'
const CARD = 'Carte commentaire'
const TEXT = 'Commentaire e2e verifiable'

test.describe('commentaires de carte (Vue)', () => {
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

	async function openCardModal(page) {
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
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill(CARD)
		await page.getByPlaceholder('Titre de la carte').press('Enter')
		await page.locator('.sk-vue-card', { hasText: CARD }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
	}

	async function typeComment(page, text) {
		await page.getByText('Ajouter un commentaire…').click()
		const rich = page.locator('.sk-comment-editor [contenteditable="true"]')
		const fallback = page.locator('.sk-comment-input')
		await rich.or(fallback).first().waitFor({ state: 'visible' })
		if (await rich.isVisible()) {
			await rich.click()
			await page.keyboard.type(text)
		} else {
			await fallback.fill(text)
		}
	}

	test('ajouter un commentaire l\'affiche, le supprimer le retire', async ({ page }) => {
		await openCardModal(page)

		await expect(page.getByText('Aucun commentaire.')).toBeVisible()

		await typeComment(page, TEXT)
		await page.getByRole('button', { name: 'Commenter' }).click()

		const body = page.locator('.sk-comment-body', { hasText: TEXT })
		await expect(body).toBeVisible()

		// Delete it.
		await page.locator('.sk-comment', { hasText: TEXT }).locator('.sk-comment-del').click()
		await expect(page.locator('.sk-comment-body', { hasText: TEXT })).toHaveCount(0)
		await expect(page.getByText('Aucun commentaire.')).toBeVisible()
	})
})
