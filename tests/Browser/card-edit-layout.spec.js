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

	test('le champ date de fin remplit sa cellule (l\'indicateur ne couvre pas la valeur)', async ({ page }) => {
		await openCardModal(page)
		// Dates are optional now: add one, then the datetime input appears. The bug
		// (calendar indicator over the value) is a field narrower than its cell —
		// compare the input to its own cell, not the modal width.
		await page.getByRole('button', { name: 'Date de fin' }).click()
		const cell = page.locator('.sk-datefield', { hasText: 'Date de fin' })
		// The input shares its row with a ✕ button; it must still flex to fill most
		// of that row (the bug was the input keeping its narrow intrinsic width).
		const row = cell.locator('.sk-datefield-input')
		const rowW = (await row.boundingBox()).width
		const fieldW = (await row.locator('input').boundingBox()).width
		expect(fieldW).toBeGreaterThanOrEqual(rowW * 0.7)
	})

	test('la description prend la pleine largeur', async ({ page }) => {
		await openCardModal(page)
		// The description is the rich Text editor when it mounts, else the textarea
		// fallback — wait for whichever becomes visible, then measure it.
		const editor = page.locator('.sk-desc-editor')
		const fallback = page.locator('.sk-desc-fallback')
		await editor.or(fallback).first().waitFor({ state: 'visible' })
		const field = (await editor.isVisible()) ? editor : fallback
		const w = (await field.boundingBox()).width
		expect(w).toBeGreaterThanOrEqual((await contentWidth(page)) * 0.9)
	})
})
