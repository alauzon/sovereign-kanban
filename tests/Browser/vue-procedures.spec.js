// The description editor's "+ Procédure" insertion in the Vue card modal (Alain,
// 2026-07-18: "il manque l'insertion de templates dans la description comme
// avant"). A fixture deposits one procedure snippet; the test inserts it and
// checks the body received it — regardless of whether the rich Text editor
// mounted or the plain textarea fallback is in use.
const { test, expect } = require('@playwright/test')
const { execSync } = require('child_process')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-proc-board'
const CARD = 'Carte procédure'
const PROC = 'zzz-e2e-proc'

function fixture(action) {
	const remote = 'pct exec 211 -- runuser -u www-data -- php /tmp/proc-fixture.php ' + action
	return execSync(
		`ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 serveur3 "${remote}"`,
		{ encoding: 'utf8' },
	).trim()
}

test.describe('insertion de procédure dans la description (Vue)', () => {
	test.beforeAll(() => {
		execSync(
			'scp -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 -q '
			+ '"' + __dirname + '/proc-fixture.php" serveur3:/tmp/proc-fixture.php '
			+ '&& ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 serveur3 '
			+ '"pct push 211 /tmp/proc-fixture.php /tmp/proc-fixture.php"',
			{ stdio: 'pipe' },
		)
		fixture('setup')
	})

	test.afterAll(() => {
		try {
			fixture('teardown')
		} catch (e) {
			// best effort
		}
	})

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

	// True if the description body (textarea value or rich editor text) contains s.
	async function descriptionHas(page, s) {
		return page.locator('.sk-desc-field').evaluate((el, needle) => {
			const ta = el.querySelector('textarea')
			const ed = el.querySelector('.sk-desc-editor')
			return ((ta ? ta.value : '') + ' ' + (ed ? ed.textContent : '')).includes(needle)
		}, s)
	}

	test('« + Procédure » insère le snippet dans la description', async ({ page }) => {
		// Board via vanilla, card + modal via Vue.
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

		// Parity check: the rich Text editor must actually mount inside the modal
		// (not silently fall back to the textarea), since that's what Alain asked
		// for. If Text is unavailable this fails loudly rather than passing on the
		// fallback.
		await expect(page.locator('.sk-desc-editor')).toBeVisible({ timeout: 15000 })

		// Nothing inserted yet.
		expect(await descriptionHas(page, 'étape un')).toBe(false)

		// Open the "+ Procédure" menu (NcActions toggle) and pick the snippet.
		// NcActionButton renders a plain <button>, not role=menuitem.
		await page.getByRole('button', { name: 'Insérer une procédure' }).click()
		await page.getByRole('button', { name: PROC }).click()

		// The body now carries the snippet.
		await expect.poll(() => descriptionHas(page, 'étape un')).toBe(true)
	})
})
