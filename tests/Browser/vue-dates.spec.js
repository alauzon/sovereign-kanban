// Optional dates with suggestions in the Vue card modal (Alain, 2026-07-18, from
// the Deck screenshot): a date starts absent (a "＋ <label>" button), can be
// added, and removed via its options menu.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-dates'
const CARD = 'Carte dates'

test.describe('dates optionnelles (Vue)', () => {
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

	async function openCardModal(page) {
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
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill(CARD)
		await page.getByPlaceholder('Titre de la carte').press('Enter')
		await page.locator('.sk-vue-card', { hasText: CARD }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
	}

	test('ajouter une date de fin, puis la retirer', async ({ page }) => {
		await openCardModal(page)
		const cell = page.locator('.sk-datefield', { hasText: 'Date de fin' })

		// Absent by default: the add button shows, no datetime input.
		await expect(cell.getByRole('button', { name: 'Date de fin' })).toBeVisible()
		await expect(cell.locator('input[type="datetime-local"]')).toHaveCount(0)

		// Add → a datetime input appears carrying a suggested value (tomorrow 08:00).
		await cell.getByRole('button', { name: 'Date de fin' }).click()
		const input = cell.locator('input[type="datetime-local"]')
		await expect(input).toBeVisible()
		expect(await input.inputValue()).toMatch(/^\d{4}-\d{2}-\d{2}T08:00$/)

		// A suggestion chip changes the value (next week ⇒ a Monday).
		await cell.getByRole('button', { name: 'Semaine prochaine' }).click()
		expect(await input.inputValue()).toMatch(/^\d{4}-\d{2}-\d{2}T08:00$/)

		// The ✕ removes the date → back to the add button, no input.
		await cell.getByRole('button', { name: 'Retirer la date' }).click()
		await expect(cell.locator('input[type="datetime-local"]')).toHaveCount(0)
		await expect(cell.getByRole('button', { name: 'Date de fin' })).toBeVisible()
	})
})
