// Two card behaviours Alain asked for on 2026-07-18:
//  - closing (✕) an open card asks Save / Discard / Delete instead of dropping
//    edits silently;
//  - a new card gets priority 3 by default.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-extras'
const CARD = 'Carte extras'

test.describe('comportements de carte (Vue)', () => {
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

	async function openCard(page) {
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

	test('une nouvelle carte a la priorité 3 par défaut', async ({ page }) => {
		await openCard(page)
		const prio = page.locator('.sk-field', { hasText: 'Priorité' }).locator('select')
		await expect(prio).toHaveValue('3')
	})

	test('le ✕ ferme directement si rien n\'a changé', async ({ page }) => {
		await openCard(page)
		// Unchanged card → ✕ closes without asking (Alain, 2026-07-19).
		await page.locator('.sk-detail-toolbar').getByRole('button', { name: 'Fermer' }).click()
		await expect(page.locator('.sk-closeconfirm')).toHaveCount(0)
		await expect(page.locator('.sk-detail-vue')).toHaveCount(0)
	})

	test('le ✕ propose Enregistrer / Revenir / Supprimer quand modifié', async ({ page }) => {
		await openCard(page)
		const dialog = page.locator('.sk-closeconfirm')

		// Only one ✕: the native modal close is hidden (Alain saw two).
		await expect(page.locator('.modal-container__close')).toBeHidden()

		// Make an edit so there is something to lose.
		await page.locator('.sk-detail-title-input').fill('Carte extras modifiée')

		// Click the modal's ✕ → the confirmation appears with three choices.
		await page.locator('.sk-detail-toolbar').getByRole('button', { name: 'Fermer' }).click()
		await expect(dialog).toBeVisible()
		await expect(dialog.getByRole('button', { name: 'Enregistrer', exact: true })).toBeVisible()
		await expect(dialog.getByRole('button', { name: 'Annuler' })).toBeVisible()
		await expect(dialog.getByRole('button', { name: 'Supprimer' })).toBeVisible()

		// « Continuer l'édition » dismisses it, the modal stays.
		await dialog.getByRole('button', { name: 'Continuer l\'édition' }).click()
		await expect(dialog).toHaveCount(0)
		await expect(page.locator('.sk-detail-vue')).toBeVisible()

		// Reopen the confirmation and discard → the modal closes.
		await page.locator('.sk-detail-toolbar').getByRole('button', { name: 'Fermer' }).click()
		await page.locator('.sk-closeconfirm').getByRole('button', { name: 'Annuler' }).click()
		await expect(page.locator('.sk-detail-vue')).toHaveCount(0)
	})
})
