// Relations between cards (Alain, 2026-07-19): pick a type, search an existing
// card by title and link it, or create a new card already linked. The link shows
// in the card's Relations section.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-relations-ui'
const CARD_A = 'Carte Alpha'
const CARD_B = 'Carte Beta'
const NEW_LINKED = 'Carte fille créée'

test.describe('relations (Vue)', () => {
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

	test('lier une carte existante par titre puis créer une carte liée', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('button', { name: 'Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.getByRole('link', { name: BOARD })).toBeVisible()
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()

		// Two cards.
		for (const name of [CARD_A, CARD_B]) {
			await page.getByRole('button', { name: '+ Carte' }).first().click()
			await page.getByPlaceholder('Titre de la carte').fill(name)
			await page.getByPlaceholder('Titre de la carte').press('Enter')
		}

		// Open A, add a relation to B by searching its title.
		await page.locator('.sk-vue-card', { hasText: CARD_A }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
		const rel = page.locator('.sk-relations')
		await expect(rel).toBeVisible()
		await rel.getByRole('button', { name: '＋ Ajouter une relation' }).click()
		await rel.getByPlaceholder('Chercher une carte par titre…').fill('Beta')
		await rel.locator('.sk-rel-result', { hasText: CARD_B }).click()

		// The link appears in the list.
		await expect(rel.locator('.sk-rel-item', { hasText: CARD_B })).toHaveCount(1)

		// Create-and-relate: a brand-new card, linked in one go.
		await rel.getByRole('button', { name: '＋ Ajouter une relation' }).click()
		await rel.getByPlaceholder('Chercher une carte par titre…').fill(NEW_LINKED)
		await rel.locator('.sk-rel-create').click()
		await expect(rel.locator('.sk-rel-item', { hasText: NEW_LINKED })).toHaveCount(1)

		// Remove the first link → it disappears.
		await rel.locator('.sk-rel-item', { hasText: CARD_B }).getByRole('button', { name: 'Retirer la relation' }).click()
		await expect(rel.locator('.sk-rel-item', { hasText: CARD_B })).toHaveCount(0)
	})
})
