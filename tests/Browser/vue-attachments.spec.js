// Card attachments (Alain, 2026-07-19): upload a file in the Pièces jointes tab,
// see it listed, download link present, then delete it.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-attach-ui'
const CARD = 'Carte à joindre'
const FILE = 'note-e2e.txt'

test.describe('pièces jointes (Vue)', () => {
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

	test('téléverser une pièce jointe, la voir listée, puis la supprimer', async ({ page }) => {
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

		// Open the attachments tab and upload a file into the hidden input.
		await page.locator('.sk-detail-vue').getByRole('button', { name: 'Pièces jointes' }).click()
		const att = page.locator('.sk-attachments')
		await expect(att).toBeVisible()
		await att.locator('.sk-att-input').setInputFiles({
			name: FILE,
			mimeType: 'text/plain',
			buffer: Buffer.from('contenu de test e2e'),
		})

		// It appears in the list, with a download link.
		const item = att.locator('.sk-att-item', { hasText: FILE })
		await expect(item).toHaveCount(1)
		await expect(item.locator('a.sk-att-name')).toHaveAttribute('href', /attachments\//)

		// Delete it → gone.
		await item.getByRole('button', { name: 'Supprimer la pièce jointe' }).click()
		await expect(att.locator('.sk-att-item', { hasText: FILE })).toHaveCount(0)
	})
})
