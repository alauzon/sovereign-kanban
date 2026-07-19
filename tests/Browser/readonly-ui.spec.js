// Browser test: a read-only board must LOOK read-only and not let you try to save.
//
// Born from a real incident (2026-07-18): Steve edited a card on a board Alain
// had shared to him READ-ONLY, hit Save, and got a bare "Erreur 403". Nothing on
// screen told him he could not write. "Je clique Enregistrer et ça plante" reads
// as data loss, not as a permission. The server was right to refuse (readonly
// enforcement, functional-tested 17/17); the UI was wrong to let him get there.
//
// Contract:
//   - a received read-only board shows a "Lecture seule" indicator;
//   - opening one of its cards, the Enregistrer button is DISABLED (you cannot
//     fire a doomed 403);
//   - a board you can write to shows no such indicator and Enregistrer works.
//
// The fixture (ro-fixture.php, run over SSH) has Test 2 create a board with a
// card and share it READ-ONLY to Test 1. The test drives Test 1.
const { test, expect } = require('@playwright/test')
const { execSync } = require('node:child_process')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-ro'
const CARD = 'Carte en lecture seule'

// The fixture runs as www-data inside CT 211, reached through serveur3.
function fixture(action) {
	const remote = 'pct exec 211 -- runuser -u www-data -- php /tmp/ro-fixture.php ' + action
	return execSync(
		`ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 serveur3 "${remote}"`,
		{ encoding: 'utf8' },
	).trim()
}

test.describe('un tableau en lecture seule le montre et bloque l\'enregistrement', () => {
	test.beforeAll(() => {
		// Ship the fixture script into the container, then set it up.
		execSync(
			'scp -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 -q '
			+ '"' + __dirname + '/ro-fixture.php" serveur3:/tmp/ro-fixture.php '
			+ '&& ssh -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 serveur3 '
			+ '"pct push 211 /tmp/ro-fixture.php /tmp/ro-fixture.php"',
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

	test('bannière lecture seule + bouton Enregistrer désactivé', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=0')
		await dismissWizard(page)

		// The received board carries the 👥 badge; open it.
		const tab = page.locator('.sk-board-tab', { hasText: BOARD })
		await expect(tab).toHaveCount(1)
		await tab.click()

		// A read-only indicator must be visible somewhere on the board.
		await expect(page.locator('.sk-readonly-banner')).toBeVisible()

		// Open the card and check the save button is disabled.
		await page.locator('article.sk-card', { hasText: CARD }).click()
		const save = page.getByRole('button', { name: 'Enregistrer', exact: true })
		await expect(save).toBeDisabled()
	})
})
