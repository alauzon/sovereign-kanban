// Playwright config for the browser tier. See README.md — credentials come
// from ~/.config/sk-e2e/env (deposited by a human, chmod 600, never printed).
const { defineConfig } = require('@playwright/test')
const { loadEnv } = require('./env')

// Lazy: listing tests must work before the credential deposit exists.
// global-setup calls loadEnv() strictly and fails loudly at RUN time.
let env
try {
	env = loadEnv()
} catch (e) {
	env = { SK_E2E_BASE_URL: 'https://cloud.tshinanu.org', STORAGE_STATE: undefined }
}

module.exports = defineConfig({
	testDir: '.',
	timeout: 45_000,
	// One worker: the tests share one account and NC throttles bursts.
	workers: 1,
	retries: 0,
	use: {
		baseURL: env.SK_E2E_BASE_URL,
		// Login once, reuse the session — NC brute-force protection throttles
		// repeated logins from one IP. Created by global-setup.
		storageState: env.STORAGE_STATE,
		locale: 'fr-CA',
		screenshot: 'only-on-failure',
	},
	globalSetup: require.resolve('./global-setup'),
	reporter: [['list']],
})
