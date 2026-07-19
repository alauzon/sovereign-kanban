// Card colour from the board palette (Alain, 2026-07-19): colour a card from its
// ⋯ menu swatches — the tile shows a left band. The board + palette + card are
// seeded server-side (the palette editor is edit-only and editing disrupts the
// board view mid-test).
const { test, expect } = require('@playwright/test')
const { execSync } = require('node:child_process')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-cardcolor'
const CARD = 'Carte à colorer'

function fixture(action) {
	const remote = 'pct exec 211 -- runuser -u www-data -- php /tmp/color-fixture.php ' + action
	return execSync(
		`ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 serveur3 "${remote}"`,
		{ encoding: 'utf8' },
	).trim()
}

test.describe('couleur de carte (Vue)', () => {
	test.beforeAll(() => {
		execSync(
			'scp -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 -q '
			+ '"' + __dirname + '/color-fixture.php" serveur3:/tmp/color-fixture.php '
			+ '&& ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 serveur3 '
			+ '"pct push 211 /tmp/color-fixture.php /tmp/color-fixture.php"',
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

	test('colorer une carte depuis le menu ⋯ affiche une bande', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()

		const card = page.locator('.sk-vue-card', { hasText: CARD })
		await expect(card).toHaveCount(1)

		// Colour it from the ⋯ menu swatches.
		await card.hover()
		await card.getByRole('button', { name: 'Menu de la carte' }).click()
		const menu = page.locator('.sk-col-menu')
		await expect(menu.locator('.sk-swatch:not(.sk-swatch-none)').first()).toBeVisible()
		await menu.locator('.sk-swatch:not(.sk-swatch-none)').first().click()

		// The tile shows a 4px left colour band.
		await expect(card).toHaveCSS('border-left-width', '4px')

		// And clearing it removes the band.
		await card.hover()
		await card.getByRole('button', { name: 'Menu de la carte' }).click()
		await page.locator('.sk-col-menu .sk-swatch-none').click()
		await expect(card).not.toHaveCSS('border-left-width', '4px')
	})
})
