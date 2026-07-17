// Log into Nextcloud ONCE and persist the session (storageState).
// NC's brute-force protection throttles repeated logins from one IP; every
// test then reuses these cookies instead of logging in again.
const { chromium } = require('@playwright/test')
const { loadEnv } = require('./env')

module.exports = async function globalSetup() {
	const env = loadEnv()
	const browser = await chromium.launch()
	const page = await browser.newPage()

	await page.goto(env.SK_E2E_BASE_URL + '/login')
	await page.fill('#user', env.SK_E2E_USER)
	await page.fill('#password', env.SK_E2E_PASS)
	await page.click('button[type="submit"]')
	// Landing anywhere authenticated is fine; the dashboard is the default.
	await page.waitForURL(/apps\//, { timeout: 20_000 })

	await page.context().storageState({ path: env.STORAGE_STATE })
	await browser.close()
}
