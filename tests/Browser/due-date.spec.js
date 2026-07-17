// Browser test: the due date keeps its TIME through the ACTUAL browser.
//
// This is the automated form of the manual check Alain was asked to do on
// 2026-07-17 — and his one-word review of that ask (« Test fonctionnel ? »)
// is why this tier exists. The functional tier proved controller→file; the
// bug's last leg (main.js: the datetime-local input and its two conversion
// functions) had no test at all.
//
// Selectors come from reading js/main.js, not from guessing:
//   #sk-new-board · #sk-form · .sk-input placeholder «Nom du tableau» ·
//   .sk-add-card · .sk-add-form input · article.sk-card ·
//   label.sk-field «Date de fin» · button «Enregistrer» ·
//   «Supprimer le tableau» + window.confirm
const { test, expect } = require('@playwright/test')

const BOARD = 'zzz-e2e-nav-due'
const CARD = 'Réunion échéance navigateur'
const DUE = '2026-07-20T14:30'

test.describe('échéance — la chaîne complète navigateur→fichier→navigateur', () => {
	test.beforeEach(async ({ page }) => {
		// window.confirm is used by the vanilla UI for deletions.
		page.on('dialog', (d) => d.accept())
		await page.goto('/apps/sovereign-kanban/')
		// Defensive: a previous killed run may have left the board behind.
		const leftover = page.locator('.sk-board-tab', { hasText: BOARD })
		if (await leftover.count()) {
			await deleteBoard(page)
		}
	})

	test.afterEach(async ({ page }) => {
		const tab = page.locator('.sk-board-tab', { hasText: BOARD })
		if (await tab.count()) {
			await deleteBoard(page)
		}
	})

	async function deleteBoard(page) {
		await page.locator('.sk-board-tab', { hasText: BOARD }).first().click()
		await page.getByRole('button', { name: 'Éditer' }).click()
		await page.getByRole('button', { name: 'Supprimer le tableau' }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(0)
	}

	test("l'heure saisie survit à l'enregistrement et au rechargement", async ({ page }) => {
		// Create a throwaway board through the UI.
		await page.locator('#sk-new-board').click()
		await page.locator('#sk-form input[placeholder="Nom du tableau"]').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(1)

		// Create a card in the first column.
		await page.locator('.sk-add-card').first().click()
		await page.locator('.sk-add-form input').fill(CARD)
		await page.locator('.sk-add-form input').press('Enter')
		const tile = page.locator('article.sk-card', { hasText: CARD })
		await expect(tile).toHaveCount(1)

		// Open it, set a due date WITH a time, save.
		await tile.click()
		const dueInput = page
			.locator('label.sk-field', { hasText: 'Date de fin' })
			.locator('input[type="datetime-local"]')
		await dueInput.fill(DUE)
		await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()

		// Reload the whole app — what the browser shows must come from the file.
		await page.reload()
		await page.locator('.sk-board-tab', { hasText: BOARD }).first().click()
		await page.locator('article.sk-card', { hasText: CARD }).click()

		// THE assertion — this is what read 00:00 before the fix.
		await expect(
			page
				.locator('label.sk-field', { hasText: 'Date de fin' })
				.locator('input[type="datetime-local"]'),
		).toHaveValue(DUE)

		// And the tile shows it human-readably (formatDateForDisplay: T → space).
		await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
		await expect(
			page.locator('article.sk-card', { hasText: CARD }).locator('.sk-due'),
		).toContainText('2026-07-20 14:30')
	})

	test("une date sans heure n'en acquiert pas une en étant rouverte", async ({ page }) => {
		await page.locator('#sk-new-board').click()
		await page.locator('#sk-form input[placeholder="Nom du tableau"]').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await page.locator('.sk-add-card').first().click()
		await page.locator('.sk-add-form input').fill(CARD)
		await page.locator('.sk-add-form input').press('Enter')
		const tile = page.locator('article.sk-card', { hasText: CARD })
		await tile.click()

		// Midnight in the picker means "no time was chosen" (main.js contract).
		const dueInput = page
			.locator('label.sk-field', { hasText: 'Date de fin' })
			.locator('input[type="datetime-local"]')
		await dueInput.fill('2026-08-01T00:00')
		await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()

		await page.reload()
		await page.locator('.sk-board-tab', { hasText: BOARD }).first().click()
		await page.locator('article.sk-card', { hasText: CARD }).click()

		// Stored as a plain date; the input renders it back at a false midnight,
		// and the TILE must show the date alone — no invented "00:00".
		await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
		const due = page.locator('article.sk-card', { hasText: CARD }).locator('.sk-due')
		await expect(due).toContainText('2026-08-01')
		await expect(due).not.toContainText('00:00')
	})
})
