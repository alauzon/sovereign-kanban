// Assignee dropdown with avatars (NcSelect user-select) in the Vue card modal
// (Alain, 2026-07-18, Deck screenshot). Search a user, pick one, check it
// persists on save/reopen.
const { test, expect } = require('@playwright/test')
const { execSync } = require('node:child_process')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-assignees'
const CARD = 'Carte assignés'
const QUERY = 'Test'

function fixture(action) {
	const remote = 'pct exec 211 -- runuser -u www-data -- php /tmp/assignee-fixture.php ' + action
	return execSync(
		`ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 serveur3 "${remote}"`,
		{ encoding: 'utf8' },
	).trim()
}

test.describe('sélecteur d\'assignés (Vue)', () => {
	test.beforeAll(() => {
		execSync(
			'scp -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 -q '
			+ '"' + __dirname + '/assignee-fixture.php" serveur3:/tmp/assignee-fixture.php '
			+ '&& ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 serveur3 '
			+ '"pct push 211 /tmp/assignee-fixture.php /tmp/assignee-fixture.php"',
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

	test('assigner une personne via le sélecteur et la persister', async ({ page }) => {
		await openCard(page)
		const field = page.locator('.sk-field', { hasText: 'Assignés' })
		const search = field.locator('input.vs__search')

		// Search → the sharees API fills the dropdown; pick the first user.
		await search.click()
		await search.fill(QUERY)
		await page.locator('.vs__dropdown-option').first().waitFor({ state: 'visible' })
		await page.locator('.vs__dropdown-option').first().click()
		await expect(field.locator('.vs__selected')).toHaveCount(1)

		// Save, reopen: the assignee stuck.
		await page.getByRole('button', { name: 'Enregistrer' }).click()
		await expect(page.locator('.sk-detail-vue')).toHaveCount(0)
		await page.locator('.sk-vue-card', { hasText: CARD }).click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
		await expect(page.locator('.sk-field', { hasText: 'Assignés' }).locator('.vs__selected')).toHaveCount(1)
	})
})
