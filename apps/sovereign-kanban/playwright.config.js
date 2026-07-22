import { defineConfig, devices } from '@playwright/test'

// e2e against a REAL Nextcloud instance (the rich Text editor + @mention menu
// only exist in a real browser — Vitest can't see them). No local webserver:
// point NC_TEST_URL at a running instance. Uses the system Chrome (channel), so
// no browser download. Credentials come from env, never hard-coded (secrets rule).
export default defineConfig({
	testDir: './e2e',
	timeout: 30_000,
	expect: { timeout: 5_000 },
	fullyParallel: false,
	use: {
		baseURL: process.env.NC_TEST_URL,
		channel: 'chrome',
		headless: true,
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'setup', testMatch: /auth\.setup\.js/ },
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'], channel: 'chrome', storageState: 'e2e/.auth/state.json' },
			dependencies: ['setup'],
			testIgnore: /auth\.setup\.js/,
		},
	],
})
