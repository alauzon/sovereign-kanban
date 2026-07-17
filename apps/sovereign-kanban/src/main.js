/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Entry point of the Vue frontend.
 *
 * Phase 1 (this file) only proves the toolchain. The vanilla js/main.js still
 * renders the whole app; nothing here runs on the live page until the PHP
 * template provides #sk-vue. That is deliberate — the cahier requires the app to
 * stay enable-able through the transition, so the migration cannot be a big bang.
 */

import { createApp } from 'vue'
import App from './App.vue'

const el = document.getElementById('sk-vue')

if (el) {
	// The marker qa-nextcloud-web made a condition of its consent. Nextcloud keys
	// asset URLs on the app version, so deploying a new bundle without bumping
	// <version> makes the browser run the OLD one — and every parity assertion
	// then passes against code we did not ship. Only this bundle can set the
	// attribute, so a smoke test can tell "the migration is live" from "the cache
	// answered". It must be RED before the shell exists and GREEN after; it is the
	// one assertion that legitimately goes red→green.
	el.dataset.skBuild = 'vue'

	createApp(App).mount(el)
}
