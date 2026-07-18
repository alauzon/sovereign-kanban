/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Build for the Vue frontend (migration décidée 2026-07-13, cahier d'ergonomie
 * « parité Deck »). createAppConfig is Nextcloud's own app preset: it emits into
 * js/ with the naming Util::addScript() expects and externalises what NC ships.
 */

import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig(
	{
		main: 'src/main.js',
	},
	{
		/**
		 * MUST stay false for the whole migration. This is not a preference.
		 *
		 * @nextcloud/vite-config ships an EmptyJSDir plugin that wipes js/ before
		 * emitting, because the Nextcloud convention is that js/ holds *generated*
		 * output only. This app breaks that convention: js/main.js is the
		 * hand-written vanilla application, 1356 lines, and it is what every user
		 * is running right now.
		 *
		 * With the default (true), the first successful build DELETES js/main.js —
		 * verified the hard way on 2026-07-15. Git got it back, and the deployed
		 * instances were never at risk since the build is local, but a release
		 * built from a clean checkout would have shipped an app with no frontend.
		 *
		 * The cahier requires the app to stay enable-able through the transition,
		 * so the two must coexist in js/ until the vanilla entry is retired at the
		 * end of the migration. Flip this back to the default only in the commit
		 * that deletes js/main.js — not before.
		 */
		emptyOutputDirectory: false,

		/**
		 * One CSS file, stable name. The default splits CSS into hashed chunks
		 * (css/main-<hash>.chunk.css) that the .mjs is supposed to lazy-load — but
		 * loaded via Util::addScript rather than as a resolved ES module, the
		 * import never fires, so @nextcloud/vue's styles never arrive and the shell
		 * renders unstyled (verified 2026-07-18). cssCodeSplit:false emits all CSS
		 * into css/sovereign-kanban-main.css, a stable name the template attaches
		 * with Util::addStyle.
		 */
		config: {
			build: {
				cssCodeSplit: false,
			},
		},
	},
)
