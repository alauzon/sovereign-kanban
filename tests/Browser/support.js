// Shared helpers for the browser tier.

/**
 * Dismiss Nextcloud's firstrunwizard modal if it is showing.
 *
 * It intercepts every click in the app. Two traps, both learned on 2026-07-17:
 *   - `#firstrunwizard` matches TWO elements (the app container AND the dialog),
 *     so target the dialog by role, not by id.
 *   - there is no generic close button (`modal-container__close` is absent on
 *     NC 34); the wizard is dismissed with its own « Passer » (skip) button.
 * Server-side persistence proved unreliable and occ cannot write the key, so
 * this runs defensively after every page load rather than trusting the server.
 */
async function dismissWizard(page) {
	const dialog = page.getByRole('dialog').filter({ has: page.locator('#firstrunwizard, .first-run-wizard') })
	// Fall back to any dialog if the class match is too tight across versions.
	const modal = (await dialog.count()) ? dialog.first() : page.getByRole('dialog').first()

	try {
		await modal.waitFor({ state: 'visible', timeout: 3_000 })
	} catch (e) {
		return // not shown
	}

	const skip = modal.getByRole('button', { name: /passer|skip/i })
	if (await skip.count()) {
		await skip.first().click()
	} else {
		// Last resort: the header X, whatever its label.
		await modal.getByRole('button', { name: /fermer|close/i }).first().click()
	}
	await modal.waitFor({ state: 'hidden', timeout: 6_000 })
}

module.exports = { dismissWizard }
