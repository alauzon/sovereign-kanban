// CHARACTERIZATION TEST — Kate's gate.
//
// This is the net for the Vue migration. It captures the OBSERVABLE behaviour of
// the central gestures as the vanilla app does them today, and it must stay green
// when each gesture is rewritten in Vue — WITHOUT being rewritten itself. If a Vue
// rewrite changes what the user sees, this goes red. That is the whole point: the
// migration may change the implementation, never the behaviour.
//
// So the assertions are deliberately SEMANTIC — roles and visible text, not CSS
// classes — because the classes are exactly what the migration will replace. A
// button labelled "+ Carte" and a card whose title is visible must survive the
// rewrite; `.sk-add-card` need not.
//
// Ordering, from the frozen oracle (2026-07-18), by real volume:
//   D2 create a card (2205), D7 rename a card (750). The two most frequent
//   *structural* gestures after writing the body (D1, which is Nextcloud's own
//   Text editor and is not ours to migrate) and dating (D3, covered by
//   due-date.spec.js). Read-only is covered by readonly-ui.spec.js.
//
// NOT yet here, and why — each needs its own technique, tracked as work:
//   D4 move (HTML5 drag-drop, unreliable to synthesize), C1 filter-by-label
//   (needs a palette + tagged fixture). Adding them fragile would be worse than
//   adding them later solid: a test that reddens for the wrong reason is noise.
//
// This file must be GREEN on the vanilla app and PROVEN able to redden (a mutation
// of the gesture makes it fail) before it is trusted — same discipline as the
// rest of the suite.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-carac'

test.describe('caractérisation — comportement observable des gestes centraux', () => {
	test.beforeEach(async ({ page }) => {
		page.on('dialog', (d) => d.accept())
		await page.goto('/apps/sovereign-kanban/?vue=0')
		await dismissWizard(page)
		if (await page.locator('.sk-board-tab', { hasText: BOARD }).count()) {
			await deleteBoard(page)
		}
	})

	test.afterEach(async ({ page }) => {
		if (await page.locator('.sk-board-tab', { hasText: BOARD }).count()) {
			await deleteBoard(page)
		}
	})

	async function newBoard(page) {
		await page.getByRole('button', { name: '+ Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(1)
	}

	async function deleteBoard(page) {
		await page.locator('.sk-board-tab', { hasText: BOARD }).first().click()
		await page.getByRole('button', { name: 'Éditer' }).click()
		await page.getByRole('button', { name: 'Supprimer le tableau' }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(0)
	}

	// D2 — the card the user creates must appear, by its title, on the board.
	test('D2 — créer une carte la fait apparaître par son titre', async ({ page }) => {
		await newBoard(page)

		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill('Première tâche')
		await page.getByPlaceholder('Titre de la carte').press('Enter')

		// Observable behaviour: a tile bearing that exact title is now on the board.
		await expect(page.getByText('Première tâche', { exact: true })).toBeVisible()
	})

	// D7 — renaming a card changes the title the user sees, everywhere.
	test('D7 — renommer une carte change le titre visible', async ({ page }) => {
		await newBoard(page)
		await page.getByRole('button', { name: '+ Carte' }).first().click()
		await page.getByPlaceholder('Titre de la carte').fill('Ancien titre')
		await page.getByPlaceholder('Titre de la carte').press('Enter')
		await expect(page.getByText('Ancien titre', { exact: true })).toBeVisible()

		// Open the card and rename it.
		await page.getByText('Ancien titre', { exact: true }).click()
		const titleField = page.locator('.sk-detail-title')
		await expect(titleField).toHaveValue('Ancien titre')
		await titleField.fill('Nouveau titre')
		await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()

		// Observable behaviour: the new title is shown, the old one is gone.
		await expect(page.getByText('Nouveau titre', { exact: true })).toBeVisible()
		await expect(page.getByText('Ancien titre', { exact: true })).toHaveCount(0)
	})
})
