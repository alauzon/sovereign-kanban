// Create a card from a template in the Vue shell (Alain, migration of the
// vanilla "📋 Carte depuis un gabarit"). A fixture deposits one template; the
// test picks it from the 📋 menu, accepts the title prompt, and checks the card
// appears carrying the template body.
const { test, expect } = require('@playwright/test')
const { execSync } = require('node:child_process')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-tpl-board'
const TPL = 'zzz-e2e-tpl'
const BODY = 'Corps du gabarit e2e'

function fixture(action) {
	const remote = 'pct exec 211 -- runuser -u www-data -- php /tmp/tpl-fixture.php ' + action
	return execSync(
		`ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 serveur3 "${remote}"`,
		{ encoding: 'utf8' },
	).trim()
}

test.describe('créer une carte depuis un gabarit (Vue)', () => {
	test.beforeAll(() => {
		execSync(
			'scp -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 -q '
			+ '"' + __dirname + '/tpl-fixture.php" serveur3:/tmp/tpl-fixture.php '
			+ '&& ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 serveur3 '
			+ '"pct push 211 /tmp/tpl-fixture.php /tmp/tpl-fixture.php"',
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
		// Accept the title prompt with its default value (= template name), and
		// any confirm (cleanup).
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

	// Pick the fixture template from the first column's footer 📋 control, whether
	// NcActions renders it as a menu (several templates) or a single direct button
	// (one template). dispatchEvent bypasses the residual navigation wait.
	async function pickTemplateInFirstColumn(page) {
		const footer = page.locator('.sk-vue-colfooter').first()
		await expect(footer).toBeVisible()
		const toggle = footer.getByRole('button', { name: 'Nouvelle carte depuis un gabarit' })
		if (await toggle.count()) {
			await toggle.click()
			await page.getByRole('button', { name: TPL, exact: false }).dispatchEvent('click')
		} else {
			await footer.getByRole('button', { name: TPL, exact: false }).dispatchEvent('click')
		}
	}

	test('le menu 📋 crée une carte portant le corps du gabarit', async ({ page }) => {
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

		await pickTemplateInFirstColumn(page)

		// The card appears (title = template name, from the accepted prompt).
		const card = page.locator('.sk-vue-card', { hasText: TPL })
		await expect(card).toBeVisible()

		// Its body carries the template content.
		await card.click()
		await expect(page.locator('.sk-detail-vue')).toBeVisible()
		const hasBody = await page.locator('.sk-desc-field').evaluate((el, needle) => {
			const ta = el.querySelector('textarea')
			const ed = el.querySelector('.sk-desc-editor')
			return ((ta ? ta.value : '') + ' ' + (ed ? ed.textContent : '')).includes(needle)
		}, BODY)
		expect(hasBody).toBe(true)
	})
})
