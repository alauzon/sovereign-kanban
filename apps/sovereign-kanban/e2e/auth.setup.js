import { test as setup, expect } from '@playwright/test'

// Logs into Nextcloud once and saves the session, so the specs run authenticated.
// Credentials MUST come from the environment — never commit a password.
// See e2e/.env.example. Use a dedicated test account WITHOUT 2FA.
setup('authenticate', async ({ page }) => {
	const url = process.env.NC_TEST_URL
	const user = process.env.NC_TEST_USER
	const pass = process.env.NC_TEST_PASSWORD
	if (!url || !user || !pass) {
		throw new Error('NC_TEST_URL, NC_TEST_USER et NC_TEST_PASSWORD requis (voir e2e/.env.example)')
	}
	await page.goto(url + '/login')
	await page.fill('input[name="user"]', user)
	await page.fill('input[name="password"]', pass)
	await page.click('button[type="submit"], input[type="submit"]')
	// Landed somewhere authenticated (dashboard, files, or an app).
	await page.waitForURL(/\/apps\/|\/dashboard|\/index\.php\/apps\//, { timeout: 15_000 })
	await page.context().storageState({ path: 'e2e/.auth/state.json' })
})
