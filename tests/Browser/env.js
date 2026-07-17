// Credential loading for the browser tier.
//
// The password is read from a file a HUMAN deposited (chmod 600) and handed
// straight to Playwright. It is never logged, never echoed, never part of a
// command line — the transcript is the exposure surface (secrets rule).
const fs = require('fs')
const os = require('os')
const path = require('path')

const ENV_FILE = path.join(os.homedir(), '.config', 'sk-e2e', 'env')

function loadEnv() {
	if (!fs.existsSync(ENV_FILE)) {
		throw new Error(
			`Missing ${ENV_FILE} — deposit the test credentials first (see README.md). `
			+ 'This is a one-time human step; the file must be chmod 600.',
		)
	}
	const mode = fs.statSync(ENV_FILE).mode & 0o777
	if (mode & 0o077) {
		throw new Error(`${ENV_FILE} is mode ${mode.toString(8)} — chmod 600 it.`)
	}

	const env = {}
	for (const line of fs.readFileSync(ENV_FILE, 'utf8').split('\n')) {
		const m = line.match(/^([A-Z0-9_]+)=(.*)$/)
		if (m) {
			env[m[1]] = m[2]
		}
	}
	for (const key of ['SK_E2E_BASE_URL', 'SK_E2E_USER', 'SK_E2E_PASS']) {
		if (!env[key]) {
			throw new Error(`${ENV_FILE} lacks ${key} (see README.md)`)
		}
	}
	env.STORAGE_STATE = path.join(os.tmpdir(), 'sk-e2e-storage-state.json')

	return env
}

module.exports = { loadEnv }
