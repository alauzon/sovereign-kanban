// Card editor layout regressions Alain found on Tshinanu, 2026-07-18.
// Tested GEOMETRICALLY (widths, not pixels): the assertion reddens only when
// the real defect is back — a field that keeps its narrow intrinsic width.
//
//   Bug: the datetime-local field kept its narrow intrinsic width, so the
//        browser's calendar indicator (drawn at the right edge) overlapped the
//        last digit of the value; and the description textarea was narrow too.
//   Norm: every field in the editor spans the modal's content width.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-cardedit'
const CARD = 'Carte test layout'

test.describe('layout de l\'éditeur de carte (Vue)', () => {
	test.beforeEach(async ({ page }) => {
		page.on('dialog', (d) => d.accept())
		// Boards can't be created in the Vue shell yet, so use the vanilla UI.
		await page.goto('/apps/sovereign-kanban/')
		await dismissWizard(page)
		await cleanup(page)
		await page.getByRole('button', { name: '+ Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(1)
	})

	test.afterEach(async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/')
		await dismissWizard(page)
		await cleanup(page)
	})

	async function cleanup(page) {
		if (await page.locator('.sk-board-tab', { hasText: BOARD }).count()) {
			await page.locator('.sk-board-tab', { hasText: BOARD }).first().click()
			await page.getByRole('button', { name: 'Éditer' }).click()
			await page.getByRole('button', { name: 'Supprimer le tableau' }).click()
			await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(0)
		}
	}

	// Open the board in Vue, add a card, open its editor modal.
	async function openCardModal(page) {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill(CARD)
		await page.getByPlaceholder('Titre de la carte').press('Enter')
		const card = page.locator('.sk-vue-card', { hasText: CARD })
		await expect(card).toHaveCount(1)
		await card.click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
	}

	// The content width available inside .sk-detail-vue (clientWidth minus its
	// horizontal padding). A full-width field must reach ~this.
	async function contentWidth(page) {
		return page.locator('.sk-detail-vue').evaluate((el) => {
			const s = getComputedStyle(el)
			return el.clientWidth - parseFloat(s.paddingLeft) - parseFloat(s.paddingRight)
		})
	}

	test('le champ date de fin prend la pleine largeur (l\'indicateur ne couvre pas la valeur)', async ({ page }) => {
		await openCardModal(page)
		const field = page.locator('.sk-field', { hasText: 'Date de fin' }).locator('input')
		const fieldW = (await field.boundingBox()).width
		expect(fieldW).toBeGreaterThanOrEqual((await contentWidth(page)) * 0.9)
	})

	test('la description prend la pleine largeur', async ({ page }) => {
		await openCardModal(page)
		const ta = page.locator('.sk-field', { hasText: 'Description' }).locator('textarea')
		const taW = (await ta.boundingBox()).width
		expect(taW).toBeGreaterThanOrEqual((await contentWidth(page)) * 0.9)
	})
})
